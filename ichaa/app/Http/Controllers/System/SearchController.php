<?php

namespace App\Http\Controllers\System;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Lore\Models\Document;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Organization\Models\Glossary;

class SearchController extends Controller
{
    public function index(Request $request): Response
    {
        $term = $request->string('q')->trim()->toString();

        if (empty($term)) {
            return $this->page('Search/Index', [
                'results' => [],
                'term'    => '',
            ]);
        }

        $results = [
            'entities'  => Entity::search($term)
                ->select(['id', 'name', 'entity_type', 'status', 'brief_description'])
                ->take(10)
                ->get(),

            'documents' => Document::search($term)
                ->select(['id', 'title', 'document_type', 'document_status'])
                ->take(5)
                ->get(),

            'secrets'   => Secret::search($term)
                ->select(['id', 'title', 'secret_type', 'exposure_risk'])
                ->take(5)
                ->get(),

            'glossary'  => Glossary::search($term)
                ->select(['id', 'term', 'usage_context'])
                ->take(5)
                ->get(),
        ];

        return $this->page('Search/Index', [
            'results' => $results,
            'term'    => $term,
        ]);
    }
}
