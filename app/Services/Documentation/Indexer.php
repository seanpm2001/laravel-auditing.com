<?php namespace App\Services\Documentation;

use ParsedownExtra;
use App\Services\CustomParser;
use App\Services\Documentation;
use Illuminate\Filesystem\Filesystem;

use  Algolia\ScoutExtended\Algolia;

class Indexer
{
    /**
     * The name of the index.
     *
     * @var string
     */
    protected static $index_name = 'prod_laravel_auditing';

    /**
     * The Algolia Index instance.
    *
     * @var \AlgoliaSearch\Index
     */
    protected $index;

    /**
     * The Algolia client instance.
    *
     * @var \AlgoliaSearch\Client
     */
    protected $client;

    /**
     * The Parsedown parser instance.
     *
     * @var ParsedownExtra
     */
    protected $markdown;

    /**
     * The filesystem instance.
     *
     * @var Filesystem
     */
    protected $files;

    /**
     * Documentation files that should not be indexed.
     *
     * @var array
     */
    protected $noIndex = [
        'contributing',
        'documentation',
        'license',
        'releases',
    ];

    /**
     * The list of HTML elements and their importance.
     *
     * @var array
     */
    protected $tags = [
        'h1' => 0,
        'h2' => 1,
        'h3' => 2,
        'h4' => 3,
        'p'  => 4,
        'td' => 4,
        'li' => 4
    ];

    /**
     * Create a new indexer service.
     *
     * @param  AlgoliaManager  $client
     * @param  CustomParser  $markdown
     * @param  Filesystem  $files
     * @return void
     */
    public function __construct(Algolia $client, CustomParser $markdown, Filesystem $files)
    {
        $this->files = $files;
        $this->client = $client->client();
        $this->markdown = $markdown;
        $this->index = $this->client->initIndex(static::$index_name.'_tmp');
    }

    /**
     * Index all of the available documentation.
     *
     * @return void
     */
    public function indexAllDocuments($versions)
    {
        foreach ($versions as $key => $title) {
            $this->indexAllDocumentsForVersion($key);
        }

        $this->setSettings();

        $this->client->moveIndex($this->index->getIndexName(), static::$index_name);
    }

    /**
     * Index all documentation for a given version.
     *
     * @param  string  $version
     * @return void
     */
    public function indexAllDocumentsForVersion($version)
    {
        $versionPath = base_raw_path($version.'/');

        foreach ($this->files->files($versionPath) as $path) {
            if (! in_array(basename($path, '.md'), $this->noIndex)) {
                $this->indexDocument($version, $path);
            }
        }
    }

    /**
     * Index a given document in Algolia
     *
     * @param string $version
     * @param string $path
     */
    public function indexDocument($version, $path)
    {
        $markdown = Documentation::replaceLinks($version, $this->files->get($path));

        $slug = basename($path, '.md');

        $blocs = $this->markdown->getBlocks($markdown);

        $markup = [];

        $current_link = $slug;
        $current_h1 = null;
        $current_h2 = null;
        $current_h3 = null;

        $excludedBlocTypes = ['Code', 'Quote', 'Markup', 'FencedCode'];

        foreach ($blocs as $bloc) {
            // If the block type should be excluded, skip it...
            if (isset($bloc['hidden']) || (isset($bloc['type']) && in_array($bloc['type'], $excludedBlocTypes)) || $bloc['element']['name'] == 'ul') {
                continue;
            }

            if (isset($bloc['type']) && $bloc['type'] == 'Table') {
                foreach ($bloc['element']['text'][1]['text'] as $tr) {
                    $markup[] = $this->getObject($tr['text'][1], $version, $current_h1, $current_h2, $current_h3, $current_h4, $current_link);
                }

                continue;
            }

            if (isset($bloc['type']) && $bloc['type'] == 'List') {
                foreach ($bloc['element']['text'] as $li) {
                    $li['text'] = $li['text'][0];

                    $markup[] = $this->getObject($li, $version, $current_h1, $current_h2, $current_h3, $current_h4, $current_link);
                }

                continue;
            }

            preg_match('/<a name=\"([^\"]*)\">.*<\/a>/iU', $bloc['element']['text'], $link);

            if (count($link) > 0) {
                $current_link = $slug . '#' . $link[1];
            } else {
                $markup[] = $this->getObject($bloc['element'], $version, $current_h1, $current_h2, $current_h3, $current_h4, $current_link);
            }
        }

        $this->index->saveObjects($markup);

        echo "Indexed $version.$slug" . PHP_EOL;
    }

    /**
     * Get the object to be indexed in Algolia.
     *
     * @param  array  $element
     * @param  string  $version
     * @param  string  $current_h1
     * @param  string  $current_h2
     * @param  string  $current_h3
     * @param  string  $current_h4
     * @param  string  $current_link
     * @return array
     */
    protected function getObject($element, $version, &$current_h1, &$current_h2, &$current_h3, &$current_h4, &$current_link)
    {
        $isContent = true;

        if ($element['name'] == 'h1') {
            $current_h1 = $element['text'];
            $current_h2 = null;
            $current_h3 = null;
            $current_h4 = null;
            $isContent = false;
        }

        if ($element['name'] == 'h2') {
            $current_h2 = $element['text'];
            $current_h3 = null;
            $current_h4 = null;
            $isContent = false;
        }

        if ($element['name'] == 'h3') {
            $current_h3 = $element['text'];
            $current_h4 = null;
            $isContent = false;
        }

        if ($element['name'] == 'h4') {
            $current_h4 = $element['text'];
            $isContent = false;
        }

        $importance = $this->tags[$element['name']];

        if ($importance === 4) {
            // Only if it's content

            if ($current_h2 !== null) {
                $importance++;
            }

            if ($current_h3 !== null) {
                $importance++;
            }

            if ($current_h4 !== null) {
                $importance++;
            }
        }

        return [
            'objectID'      => $version.'-'.$current_link.'-'.md5($element['text']),
            'h1'            => $current_h1,
            'h2'            => $current_h2,
            'h3'            => $current_h3,
            'h4'            => $current_h4,
            'link'          => $current_link,
            'content'       => $isContent ? $element['text'] : null,
            'importance'    => $importance,
            '_tags'         => [$version]
        ];
    }

    /**
     * Configure settings on the Algolia index.
     *
     * @return void
     */
    public function setSettings()
    {
        $this->index->setSettings([
            'attributesToIndex'         => ['unordered(h1)', 'unordered(h2)', 'unordered(h3)', 'unordered(h4)', 'unordered(content)'],
            'attributesToHighlight'     => ['h1', 'h2', 'h3', 'h4', 'content'],
            'attributesToRetrieve'      => ['h1', 'h2', 'h3', 'h4', '_tags', 'link'],
            'customRanking'             => ['asc(importance)'],
            'ranking'                   => ['words', 'typo', 'attribute', 'proximity', 'exact', 'custom'],
            'minWordSizefor1Typo'       => 3,
            'minWordSizefor2Typos'      => 7,
            'allowTyposOnNumericTokens' => false,
            'minProximity'              => 2,
            'ignorePlurals'             => true,
            'advancedSyntax'            => true,
            'removeWordsIfNoResults'    => 'allOptional',
        ]);
    }
}
