<?php

namespace Heyday\Elastica;

/**
 * Defines and refreshes the elastic search index.
 */
class ReindexTask extends \BuildTask
{

    protected $title = 'Elastic Search Reindex';

    protected $description = 'Refreshes the elastic search index';

    /**
     * @var ElasticaService
     */
    private $service;

    public function __construct(ElasticaService $service)
    {
        $this->service = $service;
    }

    public function run($request)
    {
		$start_time = microtime(true);

		# set the time limit to 60 minutes
		set_time_limit(60 * 60);

		// turn off Translatable local stuff
		\Translatable::disable_locale_filter();

        $message = function ($content) {
            print(\Director::is_cli() ? "$content\n" : "<p>$content</p>");
        };

        $message('Defining the mappings');
        $this->service->define();

        $message('Refreshing the index');
        $this->service->refresh();


		$end_time = microtime(true);
		$elapsed_time = $end_time - $start_time;
		$message('#####################################');
		$message("Finished in " . $elapsed_time . " seconds");
    }

}
