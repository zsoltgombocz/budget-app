<?php

namespace App\Filament\Resources\WaitlistSignups\Pages;

use App\Filament\Resources\WaitlistSignups\WaitlistSignupResource;
use App\Models\WaitlistSignup;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManageWaitlistSignups extends ManageRecords
{
    protected static string $resource = WaitlistSignupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('Download confirmed (CSV)'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (): StreamedResponse => response()->streamDownload(function (): void {
                    echo "email;signed_up;confirmed\n";

                    foreach (WaitlistSignup::query()->whereNotNull('confirmed_at')->oldest()->cursor() as $signup) {
                        echo $signup->email.';'.$signup->created_at?->format('Y-m-d H:i').';'.$signup->confirmed_at?->format('Y-m-d H:i')."\n";
                    }
                }, 'moneysight-varolista-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv'])),
        ];
    }
}
