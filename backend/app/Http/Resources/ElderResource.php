<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ElderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'birth_date' => $this->birth_date->format('Y-m-d'),
            'timezone' => $this->timezone, 'medical_information' => $this->medical_information, 'active' => $this->active, 'version' => $this->version];
    }
}
