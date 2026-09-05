<?php
declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Http\UploadedFile;

interface StatementParserInterface
{
    /**
     * РџР°СЂСЃРёС‚ С„Р°Р№Р» РІС‹РїРёСЃРєРё РІ РјР°СЃСЃРёРІ СЃС‹СЂС‹С… С‚СЂР°РЅР·Р°РєС†РёР№.
     *
     * @return array<int, array{
     *     date: string,
     *     amount: float,
     *     type: string,
     *     description: string
     * }>
     */
    public function parse(UploadedFile $file): array;
}

