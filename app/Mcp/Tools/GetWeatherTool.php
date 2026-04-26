<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('get_weather')]
#[Description('Get current weather for a city (uses Open-Meteo, free, no API key).')]
class GetWeatherTool extends Tool
{
    public function handle(Request $request): Response
    {
        $city = trim((string) ($request->get('city') ?? ''));
        if ($city === '') {
            return Response::error('city is required');
        }

        try {
            $geo = Http::timeout(10)
                ->get('https://geocoding-api.open-meteo.com/v1/search', [
                    'name' => $city,
                    'count' => 1,
                    'language' => 'en',
                    'format' => 'json',
                ]);

            if (! $geo->successful()) {
                return Response::error('Geocoding API error: HTTP '.$geo->status());
            }

            $place = $geo->json('results.0');
            if (! is_array($place)) {
                return Response::error("City not found: {$city}");
            }

            $lat = (float) $place['latitude'];
            $lon = (float) $place['longitude'];
            $name = (string) ($place['name'] ?? $city);
            $country = (string) ($place['country'] ?? '');

            $weather = Http::timeout(10)
                ->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'current' => 'temperature_2m,relative_humidity_2m,wind_speed_10m,weather_code,apparent_temperature',
                    'timezone' => 'auto',
                ]);

            if (! $weather->successful()) {
                return Response::error('Weather API error: HTTP '.$weather->status());
            }

            $current = $weather->json('current');
            if (! is_array($current)) {
                return Response::error('Unexpected weather API response');
            }

            return Response::json([
                'city' => $name,
                'country' => $country,
                'latitude' => $lat,
                'longitude' => $lon,
                'observed_at' => $current['time'] ?? null,
                'temperature_c' => $current['temperature_2m'] ?? null,
                'apparent_temperature_c' => $current['apparent_temperature'] ?? null,
                'humidity_pct' => $current['relative_humidity_2m'] ?? null,
                'wind_speed_kmh' => $current['wind_speed_10m'] ?? null,
                'weather_code' => $current['weather_code'] ?? null,
                'weather_description' => $this->describeWeatherCode((int) ($current['weather_code'] ?? 0)),
            ]);
        } catch (Throwable $e) {
            return Response::error($e->getMessage());
        }
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()
                ->description('City name (English or local). Example: "Tashkent", "Toshkent", "Moscow"')
                ->required(),
        ];
    }

    /**
     * Open-Meteo WMO weather code → human description.
     */
    private function describeWeatherCode(int $code): string
    {
        return match (true) {
            $code === 0 => 'Clear sky',
            $code === 1 => 'Mainly clear',
            $code === 2 => 'Partly cloudy',
            $code === 3 => 'Overcast',
            $code === 45, $code === 48 => 'Fog',
            $code >= 51 && $code <= 55 => 'Drizzle',
            $code >= 56 && $code <= 57 => 'Freezing drizzle',
            $code >= 61 && $code <= 65 => 'Rain',
            $code >= 66 && $code <= 67 => 'Freezing rain',
            $code >= 71 && $code <= 75 => 'Snow',
            $code === 77 => 'Snow grains',
            $code >= 80 && $code <= 82 => 'Rain showers',
            $code >= 85 && $code <= 86 => 'Snow showers',
            $code === 95 => 'Thunderstorm',
            $code === 96, $code === 99 => 'Thunderstorm with hail',
            default => 'Unknown',
        };
    }
}
