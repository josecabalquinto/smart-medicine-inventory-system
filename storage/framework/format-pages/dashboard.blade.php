<?php
use App\Models\{ApplicationSetting, InventoryCount, Medicine, Scale};
use App\Services\DeviceReadingService;
use Livewire\Attributes\{Computed,Title};
use Livewire\Component;
new #[Title('Dashboard')] class extends Component {
    #[Computed] public function summary(): array {
        $base=Medicine::where('simulation',false)->where('active',true);
        return ['active'=>(clone $base)->count(),'low'=>(clone $base)->whereNotNull('quantity')->whereColumn('quantity','<=','low_stock_threshold')->count(),'uncounted'=>(clone $base)->whereNull('quantity')->count()];
    }
    #[Computed] public function recent() { return InventoryCount::with(['medicine','user'])->whereHas('medicine',fn($q)=>$q->where('simulation',false))->latest('id')->limit(5)->get(); }
    #[Computed] public function lowStock() { return Medicine::where('simulation',false)->where('active',true)->whereNotNull('quantity')->whereColumn('quantity','<=','low_stock_threshold')->orderBy('quantity')->limit(5)->get(); }
    #[Computed] public function scaleStatus() { return app(DeviceReadingService::class)->status(Scale::where('simulation',false)->first()); }
    #[Computed] public function pharmacyName() { return ApplicationSetting::findOrFail(1)->name; }
}; 
