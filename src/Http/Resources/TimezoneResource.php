<?php

namespace FlutterSdk\MagicStarter\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms a timezone data array into an API-ready response.
 *
 * Unlike most resources in this package, this wraps a plain array
 * (sourced from PHP's DateTimeZone) rather than an Eloquent model.
 */
class TimezoneResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'identifier' => $this->resource['identifier'],
            'label' => $this->resource['label'],
            'offset' => $this->resource['offset'],
            'offset_minutes' => $this->resource['offset_minutes'],
            'region' => $this->resource['region'],
        ];
    }
}
