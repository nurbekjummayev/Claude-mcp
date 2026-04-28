<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class FetchTrademarksCommand extends Command
{
    protected $signature = 'trademarks:fetch
        {--token= : Bearer token for API authentication}
        {--output=trademark : Output folder for JSON files}
        {--size=100 : Page size for API requests}
        {--delay=2000 : Delay between requests in milliseconds}
        {--tor : Use Tor proxy for requests}
        {--tor-proxy=socks5h://127.0.0.1:9050 : Tor SOCKS5 proxy address}
        {--tor-control=127.0.0.1:9051 : Tor control port for circuit rotation}
        {--tor-password= : Tor control password (if set)}
        {--retry=3 : Number of retries on 429 error}
        {--skip-existing : Skip files that already exist}';

    protected $description = 'Fetch trademarks from adliya.uz API and save as JSON files';

    private const BASE_URL = 'https://api-ip.adliya.uz/v1/register/public/search';

    private string $token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VyX25hbWUiOiIyMDgwNCIsImF1dGhvcml0aWVzIjpbXSwiY2xpZW50X2lkIjoiZnJvbnRfb2ZmaWNlIiwiZG9jdW1lbnRJZHMiOm51bGwsInVzZXJfdHlwZSI6IklORElWSURVQUwiLCJ1c2VyX2lkIjoyMDgwNCwidXNlcl9pbmZvIjp7ImlkIjoyMDgwNCwicGluIjoiNTE2MTAwMjc0NDAwMjIiLCJmaXJzdF9uYW1lIjoiTlVSQkVLIiwibGFzdF9uYW1lIjoiSlVNTUFZRVYiLCJtaWRkbGVfbmFtZSI6IlVMVUfigJhCRVJESSBP4oCYR-KAmExJIiwicGhvdG8iOm51bGx9LCJzY29wZSI6WyJyZWFkIiwid3JpdGUiXSwib3JnYW5pemF0aW9uIjpudWxsLCJleHBlcnRfc3RhdHVzIjpudWxsLCJsZWdhbF9lbnRpdHkiOnsidGluIjpudWxsLCJuYW1lIjoiIn0sImV4cCI6MTc3NzQ0MTAxMSwiZGVwYXJ0bWVudCI6bnVsbCwianRpIjoiNjVkZjM0YzUtMjg3YS00N2I2LTkwZDUtOGZkODVhZDczYTg3In0._mX2QXgg06zUktLUnCFzExsANRH4DKu-ECR4bBskglc';

    private bool $useTor = false;

    private string $torProxy = '';

    private string $torControl = '';

    private string $torPassword = '';

    private int $retryCount = 3;

    private int $delay = 2000;

    public function handle(): int
    {
        $this->useTor = (bool) $this->option('tor');
        $this->torProxy = (string) $this->option('tor-proxy');
        $this->torControl = (string) $this->option('tor-control');
        $this->torPassword = (string) $this->option('tor-password');
        $this->retryCount = (int) $this->option('retry');
        $this->delay = (int) $this->option('delay');
        $skipExisting = (bool) $this->option('skip-existing');

        $outputDir = (string) $this->option('output');
        $size = (int) $this->option('size');

        // Ensure output directory exists
        $outputPath = base_path($outputDir);
        if (! File::isDirectory($outputPath)) {
            File::makeDirectory($outputPath, 0755, true);
        }

        $this->info("Output directory: {$outputPath}");

        if ($this->useTor) {
            $this->info("Using Tor proxy: {$this->torProxy}");
            $this->showCurrentIp();
        }

        $page = 0;
        $totalProcessed = 0;
        $totalSkipped = 0;
        $totalPages = 1;

        $progressBar = null;

        do {
            $this->newLine();
            $this->info("Fetching page {$page}...");

            // Delay before search request
            if ($page > 0) {
                usleep($this->delay * 1000);
            }

            $response = $this->searchTrademarksWithRetry($page, $size);

            if ($response === null) {
                $this->error("Failed to fetch page {$page} after {$this->retryCount} retries");

                return self::FAILURE;
            }

            if ($page === 0) {
                $totalPages = $response['total_pages'] ?? 1;
                $totalCount = $response['total_count'] ?? 0;
                $this->info("Total trademarks found: {$totalCount} ({$totalPages} pages)");
                $progressBar = $this->output->createProgressBar($totalCount);
                $progressBar->start();
            }

            $items = $response['data'] ?? [];

            foreach ($items as $item) {
                $applicationNumber = $item['APPLICATION']['number'] ?? null;

                if ($applicationNumber === null) {
                    $this->warn('Skipping item without application number');

                    continue;
                }

                $filename = $outputPath.'/'.$applicationNumber.'.json';

                // Skip if file exists and skip-existing is enabled
                if ($skipExisting && File::exists($filename)) {
                    $totalSkipped++;
                    $progressBar?->advance();

                    continue;
                }

                // Delay between requests
                usleep($this->delay * 1000);

                // Fetch detail for this item
                $detail = $this->fetchDetailWithRetry($applicationNumber);

                // Combine item and detail
                $combined = [
                    'item' => $item,
                    'detail' => $detail,
                ];

                // Save to JSON file
                File::put($filename, json_encode($combined, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                $totalProcessed++;
                $progressBar?->advance();
            }

            $page++;

        } while ($page < $totalPages);

        $progressBar?->finish();
        $this->newLine(2);
        $this->info("Successfully processed {$totalProcessed} trademarks");
        if ($totalSkipped > 0) {
            $this->info("Skipped {$totalSkipped} existing files");
        }

        return self::SUCCESS;
    }

    private function showCurrentIp(): void
    {
        try {
            $response = $this->makeRequest('GET', 'https://api.ipify.org?format=json');
            $ip = $response->body() ?? 'unknown';
            $this->info("Current IP: {$ip}");
        } catch (Exception $e) {
            $this->warn('Could not determine current IP');
        }
    }

    private function rotateTorCircuit(): bool
    {
        if (! $this->useTor) {
            return false;
        }

        $this->info('Rotating Tor circuit...');

        try {
            [$host, $port] = explode(':', $this->torControl);
            $socket = @fsockopen($host, (int) $port, $errno, $errstr, 10);

            if (! $socket) {
                $this->warn("Could not connect to Tor control port: {$errstr}");

                return false;
            }

            // Authenticate
            if ($this->torPassword !== '') {
                fwrite($socket, "AUTHENTICATE \"{$this->torPassword}\"\r\n");
            } else {
                fwrite($socket, "AUTHENTICATE\r\n");
            }
            $authResponse = fgets($socket);

            if (! str_starts_with($authResponse, '250')) {
                $this->warn("Tor authentication failed: {$authResponse}");
                fclose($socket);

                return false;
            }

            // Request new circuit
            fwrite($socket, "SIGNAL NEWNYM\r\n");
            $signalResponse = fgets($socket);
            fclose($socket);

            if (str_starts_with($signalResponse, '250')) {
                $this->info('Tor circuit rotated successfully');
                sleep(5); // Wait for new circuit
                $this->showCurrentIp();

                return true;
            }

            $this->warn("Failed to rotate Tor circuit: {$signalResponse}");

            return false;
        } catch (Exception $e) {
            $this->warn('Error rotating Tor circuit: '.$e->getMessage());

            return false;
        }
    }

    /**
     * @return Response
     */
    private function makeRequest(string $method, string $url, array $options = [])
    {
        $http = Http::withHeaders($options['headers'] ?? [])
            ->timeout(30);

        if ($this->useTor) {
            $http = $http->withOptions([
                'proxy' => $this->torProxy,
            ]);
        }

        if ($method === 'POST') {
            return $http->post($url, $options['body'] ?? []);
        }

        return $http->get($url, $options['query'] ?? []);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function searchTrademarksWithRetry(int $page, int $size): ?array
    {
        for ($attempt = 1; $attempt <= $this->retryCount; $attempt++) {
            $result = $this->searchTrademarks($page, $size);

            if ($result !== null) {
                return $result;
            }

            // If we got rate limited, rotate IP and retry
            if ($this->useTor && $attempt < $this->retryCount) {
                $this->warn("Search attempt {$attempt} failed, rotating Tor circuit...");
                $this->rotateTorCircuit();
                sleep(5); // Wait longer for search
            } elseif ($attempt < $this->retryCount) {
                $this->warn("Search attempt {$attempt} failed, waiting before retry...");
                sleep(10); // Wait without Tor
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function searchTrademarks(int $page, int $size): ?array
    {
        try {
            $response = $this->makeRequest('POST', self::BASE_URL.'?objectType=TRADEMARK', [
                'headers' => [
                    'Authorization' => "Bearer {$this->token}",
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json; charset=UTF-8',
                    'Referer' => 'https://im.adliya.uz/',
                    'User-Agent' => $this->getRandomUserAgent(),
                ],
                'body' => [
                    'size' => $size,
                    'page' => $page,
                    'sort' => [
                        'name' => 'number',
                        'direction' => 'desc',
                    ],
                    'lang' => 'uz',
                    'search' => [
                        [
                            'key' => 'APPLICATION__application_status',
                            'value' => 'COMPLETED',
                            'operation' => 'EQUALITY',
                            'prefix' => 'APPLICATION',
                        ],
                    ],
                    'response_data' => [],
                ],
            ]);

            if ($response->status() === 429) {
                $this->warn('Search API rate limited (429)');

                return null;
            }

            if ($response->failed()) {
                $this->error('Search API error: '.$response->status());

                return null;
            }

            return $response->json();
        } catch (ConnectionException $e) {
            $this->warn('Search connection error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchDetailWithRetry(int|string $applicationNumber): ?array
    {
        for ($attempt = 1; $attempt <= $this->retryCount; $attempt++) {
            $result = $this->fetchDetail($applicationNumber);

            if ($result !== null) {
                return $result;
            }

            // If we got rate limited, rotate IP and retry
            if ($this->useTor && $attempt < $this->retryCount) {
                $this->warn("Attempt {$attempt} failed, rotating Tor circuit...");
                $this->rotateTorCircuit();
                sleep(2);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchDetail(int|string $applicationNumber): ?array
    {
        try {
            $response = $this->makeRequest('GET', self::BASE_URL."/{$applicationNumber}", [
                'headers' => [
                    'Authorization' => "Bearer {$this->token}",
                    'Accept' => 'application/json',
                    'Referer' => 'https://im.adliya.uz/',
                    'User-Agent' => $this->getRandomUserAgent(),
                ],
                'query' => [
                    'objectType' => 'TRADEMARK',
                ],
            ]);

            if ($response->status() === 429) {
                $this->warn("Rate limited (429) for {$applicationNumber}");

                return null;
            }

            if ($response->failed()) {
                $this->warn("Detail API error for {$applicationNumber}: ".$response->status());

                return null;
            }

            $data = $response->json();

            return $data['data'] ?? null;
        } catch (ConnectionException $e) {
            $this->warn("Connection error for {$applicationNumber}: ".$e->getMessage());

            return null;
        }
    }

    private function getRandomUserAgent(): string
    {
        $userAgents = [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0',
        ];

        return $userAgents[array_rand($userAgents)];
    }
}
