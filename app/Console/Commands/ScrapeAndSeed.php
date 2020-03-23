<?php

namespace App\Console\Commands;

use App\Report;
use App\Country;
use App\Province;
use Carbon\Carbon;
use League\Csv\Reader;
use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ScrapeAndSeed extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrape:seed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape and seed the database.';

    /**
     * Local storage/app/{DIRECTORY} path
     */
    const DIRECTORY = 'covid-data';

    /**
     * The path to daily reports within the repository.
     */
    const REPORTS_PATH = 'csse_covid_19_data/csse_covid_19_daily_reports';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Storage::deleteDirectory(self::DIRECTORY);

        $path = storage_path('app/' . self::DIRECTORY);

        shell_exec("git clone https://github.com/CSSEGISandData/COVID-19.git {$path}");

        $this->call('migrate:fresh');

        $this->dailyReports()->each(function ($path) {
            $date = Str::replaceFirst('.csv', '', basename($path));
            $this->line("Importing {$date}");

            $csv = Reader::createFromString(Storage::get($path));
            $csv->setHeaderOffset(0);

            collect($csv->getRecords())->each(function ($report) use ($date) {
                $this->findOrCreateModels($report, $date);
            });
        });

        Storage::deleteDirectory(self::DIRECTORY);
    }

    /**
     * @param $report
     * @param string $date
     */
    public function findOrCreateModels($report, string $date): void
    {
        $country = Country::firstOrCreate([
            'name' => $report['Country/Region'],
        ])->id;

        $province = $report['Province/State']
            ? Province::firstOrCreate([
                'name' => $report['Province/State'],
                'country_id' => $country,
            ])->id
            : null;

        $reportIdentifiers = [
            'date' => Carbon::createFromFormat('m-d-Y', $date),
            'country_id' => $country,
            'province_id' => $province,
        ];

        $reportMetrics = [
            'deaths' => $report['Deaths'] ?: 0,
            'confirmed' => $report['Confirmed'] ?: 0,
            'recovered' => $report['Recovered'] ?: 0,
        ];

        Report::create(array_merge($reportIdentifiers, $reportMetrics));
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function dailyReports()
    {
        return collect(Storage::files($this->dailyReportsPath()))->filter(function ($path) {
            return Str::contains($path, '.csv');
        });
    }

    public function dailyReportsPath()
    {
        return self::DIRECTORY . '/' . self::REPORTS_PATH;
    }
}