<?php

namespace App\Filament\Resources\Groups\RelationManagers;

use Filament\Schemas\Schema;
use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\Channels\Pages\ListChannels;
use App\Models\Channel;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Hydrat\TableLayoutToggle\Concerns\HasToggleableTable;

class ChannelsRelationManager extends RelationManager
{
    // use HasToggleableTable;

    protected static string $relationship = 'live_channels';

    protected static ?string $label = 'Live Channels';
    protected static ?string $pluralLabel = 'Live Channels';

    protected static ?string $title = 'Live Channels';
    protected static ?string $navigationLabel = 'Live Channels';

    protected $listeners = ['refreshRelation' => '$refresh'];

    public function isReadOnly(): bool
    {
        return false;
    }
    
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(ChannelResource::getForm());
    }

    public function infolist(Schema $schema): Schema
    {
        return ChannelResource::infolist($schema);
    }

    public function table(Table $table): Table
    {
        $table = $table->reorderRecordsTriggerAction(function ($action) {
            return $action->button()->label('Sort');
        })->defaultSort('sort', 'asc')->reorderable('sort');
        return ChannelResource::setupTable($table, $this->ownerRecord->id);
    }

    public function getTabs(): array
    {
        return ListChannels::setupTabs($this->ownerRecord->id);
    }
}
