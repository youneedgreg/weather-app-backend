<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class WeatherService
{
    protected $apiKey;
    protected $baseUrl = 'https://api.openweathermap.org/data/2.5';
    protected $geoUrl = 'https://api.openweathermap.org/geo/1.0';

    public function __construct()
    {
        $this->apiKey = env('OPENWEATHERMAP_API_KEY');
    }

    /**
     * Search for city coordinates using the geocoding API
     *
     * @param string $query The city name to search for
     * @return array|null City data or null if not found
     */
    public function searchCity(string $query)
    {
        $cacheKey = 'city_search_' . md5($query);
        
        return Cache::remember($cacheKey, 60 * 60, function () use ($query) {
            $response = Http::get("{$this->geoUrl}/direct", [
                'q' => $query,
                'limit' => 5,
                'appid' => $this->apiKey
            ]);
            
            if ($response->successful() && !empty($response->json())) {
                return $response->json();
            }
            
            return null;
        });
    }

    /**
     * Get current weather for a location
     *
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @param string $units Units (metric or imperial)
     * @return array|null Weather data or null if not found
     */
    public function getCurrentWeather(float $lat, float $lon, string $units = 'metric')
    {
        $cacheKey = "current_weather_{$lat}_{$lon}_{$units}";
        
        return Cache::remember($cacheKey, 30 * 60, function () use ($lat, $lon, $units) {
            $response = Http::get("{$this->baseUrl}/weather", [
                'lat' => $lat,
                'lon' => $lon,
                'units' => $units,
                'appid' => $this->apiKey
            ]);
            
            if ($response->successful()) {
                return $response->json();
            }
            
            return null;
        });
    }

    /**
     * Get weather forecast for a location
     *
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @param string $units Units (metric or imperial)
     * @return array|null Forecast data or null if not found
     */
    public function getForecast(float $lat, float $lon, string $units = 'metric')
    {
        $cacheKey = "forecast_{$lat}_{$lon}_{$units}";
        
        return Cache::remember($cacheKey, 60 * 60, function () use ($lat, $lon, $units) {
            $response = Http::get("{$this->baseUrl}/forecast", [
                'lat' => $lat,
                'lon' => $lon,
                'units' => $units,
                'appid' => $this->apiKey
            ]);
            
            if ($response->successful()) {
                // Process the forecast data to get daily forecasts
                $data = $response->json();
                return $this->processForecastData($data);
            }
            
            return null;
        });
    }

    /**
     * Process forecast data to get daily forecasts
     *
     * @param array $data Raw forecast data
     * @return array Processed forecast data
     */
    protected function processForecastData(array $data)
    {
        $dailyForecasts = [];
        $forecastList = $data['list'];
        $currentDate = null;
        $dayData = null;
        
        foreach ($forecastList as $forecast) {
            $date = date('Y-m-d', $forecast['dt']);
            
            // If we've moved to a new day
            if ($currentDate !== $date) {
                // Save the previous day's data if it exists
                if ($dayData !== null) {
                    $dailyForecasts[] = $dayData;
                }
                
                // Start a new day
                $currentDate = $date;
                $dayData = $forecast;
                $dayData['date'] = $date;
                
                // Initialize min/max temps with the first value
                $dayData['main']['temp_min'] = $forecast['main']['temp_min'];
                $dayData['main']['temp_max'] = $forecast['main']['temp_max'];
            } else {
                // Update min/max temps if needed
                if ($forecast['main']['temp_min'] < $dayData['main']['temp_min']) {
                    $dayData['main']['temp_min'] = $forecast['main']['temp_min'];
                }
                if ($forecast['main']['temp_max'] > $dayData['main']['temp_max']) {
                    $dayData['main']['temp_max'] = $forecast['main']['temp_max'];
                }
            }
        }
        
        // Add the last day if it exists
        if ($dayData !== null) {
            $dailyForecasts[] = $dayData;
        }
        
        // Limit to 3 days as required
        $dailyForecasts = array_slice($dailyForecasts, 0, 3);
        
        return [
            'city' => $data['city'],
            'daily' => $dailyForecasts
        ];
    }
}