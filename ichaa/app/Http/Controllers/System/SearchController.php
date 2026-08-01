<?php

namespace App\Http\Controllers\System;

use App\Domain\Identity\Models\Entity;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Lore\Models\Document;
use App\Domain\Organization\Models\Glossary;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Response;

class SearchController extends Controller
{
    public function index(Request $request): Response
    {
        $term = $request->string('q')->trim()->toString();

        if (empty($term)) {
            return $this->page('Search/Index', [
                'results' => [],
                'term' => '',
            ]);
        }

        $entityResults = Entity::search($term)
            ->select(['id', 'name', 'entity_type', 'status', 'summary'])
            ->take(10)
            ->get();

        $documentResults = Document::search($term)
            ->select(['id', 'title', 'document_type', 'document_status'])
            ->take(5)
            ->get();

        $secretResults = Secret::search($term)
            ->select(['id', 'title', 'secret_type', 'exposure_risk'])
            ->take(5)
            ->get();

        $glossaryResults = Glossary::search($term)
            ->select(['id', 'term', 'usage_context'])
            ->take(5)
            ->get();

        $results = [
            'entities' => $entityResults,
            'documents' => $documentResults,
            'secrets' => $secretResults,
            'glossary' => $glossaryResults,
        ];

        return $this->page('Search/Index', [
            'results' => $results,
            'term' => $term,
        ]);
    }
}
