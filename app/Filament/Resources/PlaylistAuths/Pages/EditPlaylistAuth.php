<?php

namespace App\Filament\Resources\PlaylistAuths\Pages;

use Filament\Actions\DeleteAction;
use App\Filament\Resources\PlaylistAuths\PlaylistAuthResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPlaylistAuth extends EditRecord
{
    protected static string $resource = PlaylistAuthResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave()
    {
        $this->dispatch('refreshRelation');
    }
}
