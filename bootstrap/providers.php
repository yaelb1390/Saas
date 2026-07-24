<?php

use App\Modules\AI\Providers\AiServiceProvider;
use App\Modules\Billing\Providers\BillingServiceProvider;
use App\Modules\Cash\Providers\CashServiceProvider;
use App\Modules\Core\Providers\CoreServiceProvider;
use App\Modules\CRM\Providers\CrmServiceProvider;
use App\Modules\Delivery\Providers\DeliveryServiceProvider;
use App\Modules\Finance\Providers\FinanceServiceProvider;
use App\Modules\HR\Providers\HrServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Loans\Providers\LoansServiceProvider;
use App\Modules\POS\Providers\POSServiceProvider;
use App\Modules\Purchasing\Providers\PurchasingServiceProvider;
use App\Modules\Reports\Providers\ReportsServiceProvider;
use App\Modules\Sales\Providers\SalesServiceProvider;
use App\Modules\WhatsApp\Providers\WhatsAppServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\PortalCacheServiceProvider;

return [
    AppServiceProvider::class,
    CoreServiceProvider::class,
    InventoryServiceProvider::class,
    PurchasingServiceProvider::class,
    CashServiceProvider::class,
    SalesServiceProvider::class,
    POSServiceProvider::class,
    BillingServiceProvider::class,
    CrmServiceProvider::class,
    WhatsAppServiceProvider::class,
    AiServiceProvider::class,
    DeliveryServiceProvider::class,
    FinanceServiceProvider::class,
    LoansServiceProvider::class,
    HrServiceProvider::class,
    ReportsServiceProvider::class,
    FortifyServiceProvider::class,
    PortalCacheServiceProvider::class,
];
