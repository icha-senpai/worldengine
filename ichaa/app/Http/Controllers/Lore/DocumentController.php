<?php

namespace App\Http\Controllers\Lore;

use Illuminate\Http\Request;
use Inertia\Response;

use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Lore\Models\Document;

class DocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Document::extant()->orderBy('title');

        if ($request->filled('type')) {
            $query->ofType($request->type);
        }

        return $this->page('Lore/Documents/Index', [
            'documents'     => $query->paginate(40)->withQueryString(),
            'filters'       => $request->only(['type']),
            'documentTypes' => Document::DOCUMENT_TYPES,
        ]);
    }

    public function create(): Response
    {
        return $this->page('Lore/Documents/Create', [
            'entities'            => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'documentTypes'     => Document::DOCUMENT_TYPES,
            'documentStatuses'  => Document::DOCUMENT_STATUSES,
            'authenticityStates'=> Document::AUTHENTICITY_STATES,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'title'                     => ['required', 'string', 'max:255'],
            'document_type'             => ['required', 'string', 'in:' . implode(',', Document::DOCUMENT_TYPES)],
            'document_authenticity'     => ['nullable', 'string'],
            'document_status'           => ['nullable', 'string'],
            'official_narrative'        => ['nullable', 'array'],
            'true_content'              => ['nullable', 'array'],
            'official_author_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
            'true_author_entity_id'     => ['nullable', 'integer', 'exists:entities,id'],
            'era_created'               => ['nullable', 'string'],
        ]);

        $document = Document::create($validated);

        return $this->to('documents.show', [$document], "Document '{$document->title}' created.");
    }

    public function show(Document $document): Response
    {
        $document->load(['officialAuthor:id,name', 'trueAuthor:id,name', 'owner:id,name']);

        return $this->page('Lore/Documents/Show', [
            'document' => $document,
        ]);
    }

    public function edit(Document $document): Response
    {
        return $this->page('Lore/Documents/Edit', [
            'entities'            => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'document'          => $document,
            'documentTypes'     => Document::DOCUMENT_TYPES,
            'documentStatuses'  => Document::DOCUMENT_STATUSES,
            'authenticityStates'=> Document::AUTHENTICITY_STATES,
        ]);
    }

    public function update(Request $request, Document $document): \Illuminate\Http\RedirectResponse
    {
        $document->update($request->validate([
            'title'                   => ['sometimes', 'string'],
            'document_authenticity'   => ['nullable', 'string'],
            'document_status'         => ['nullable', 'string'],
            'official_narrative'      => ['nullable', 'array'],
            'true_content'            => ['nullable', 'array'],
            'true_author_entity_id'   => ['nullable', 'integer'],
            'suppressed_by_entity_id' => ['nullable', 'integer'],
        ]));

        return $this->to('documents.show', [$document], 'Document updated.');
    }

    public function destroy(Document $document): \Illuminate\Http\RedirectResponse
    {
        $document->delete();

        return $this->to('documents.index', [], 'Document deleted.');
    }
}
