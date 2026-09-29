<?php

declare(strict_types=1);

use Capell\Core\Http\Controllers\Reporting\ReportingHealthController;
use Illuminate\Support\Facades\Route;

Route::get('/_capell/reporting/health', ReportingHealthController::class)->middleware('throttle:30,1,capell-reporting-health:')->name('capell.reporting.health');
