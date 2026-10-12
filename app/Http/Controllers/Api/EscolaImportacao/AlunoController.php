<?php

namespace App\Http\Controllers\Api\EscolaImportacao;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\EscolaImportacao\AlunoIndexRequest;
use App\Http\Resources\EscolaImportacao\AlunoResource;
use App\Models\SchoolInep;
use App\Services\EscolaImportacao\AlunoQuery;
use Illuminate\Http\JsonResponse;

class AlunoController extends Controller
{
    public function index(AlunoIndexRequest $request, AlunoQuery $query): JsonResponse
    {
        $school = SchoolInep::query()->where('cod_escola_inep', $request->inep())->first();

        if ($school === null) {
            return response()->json(['message' => 'Escola não encontrada para o INEP informado.'], 404);
        }

        $enrollments = $query->get($school->cod_escola, $request->year(), $request->cpf());

        return response()->json(AlunoResource::collection($enrollments)->resolve($request));
    }
}
