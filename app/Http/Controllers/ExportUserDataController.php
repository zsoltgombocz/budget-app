<?php

namespace App\Http\Controllers;

use App\Actions\Account\ExportUserData;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportUserDataController extends Controller
{
    /**
     * "Adataim letöltése": a ZIP with everything stored about the signed-in user.
     */
    public function __invoke(Request $request, ExportUserData $export): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return response()
            ->download($export->handle($user), 'moneysight-adatok-'.now()->format('Y-m-d').'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }
}
