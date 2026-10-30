<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Unidade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UnidadeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/units', [
            'units' => Unidade::query()->orderBy('nome')->get(['id', 'nome', 'ativo']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nome' => ['required', 'string', 'max:255', 'unique:unidades,nome'],
        ]);

        Unidade::query()->create($data);

        return back()->with('success', 'Unidade criada.');
    }

    public function update(Request $request, Unidade $unidade): RedirectResponse
    {
        $data = $request->validate([
            'nome' => [
                'required', 'string', 'max:255',
                Rule::unique('unidades', 'nome')->ignore($unidade->id),
            ],
        ]);

        $unidade->update($data);

        return back()->with('success', 'Unidade atualizada.');
    }

    public function setActive(Request $request, Unidade $unidade): RedirectResponse
    {
        $data = $request->validate([
            'ativo' => ['required', 'boolean'],
        ]);

        $unidade->update(['ativo' => $data['ativo']]);

        return back()->with('success', 'Status da unidade atualizado.');
    }
}
