<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\LegacyLeadImportController;
use App\Http\Controllers\Admin\UnidadeController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AgendamentoController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CampanhaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\RelatorioController;
use App\Http\Controllers\TurmaController;
use App\Http\Controllers\VendedorController;
use App\Http\Controllers\VisitaController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->name('login.store');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/', DashboardController::class)->name('home');
    Route::get('/campanhas', [CampanhaController::class, 'index'])->name('campanhas.index');
    Route::get('/relatorios', [RelatorioController::class, 'index'])->name('relatorios.index');
    Route::get('/relatorios/csv', [RelatorioController::class, 'csv'])->name('relatorios.csv');
    Route::get('/relatorios/pdf', [RelatorioController::class, 'pdf'])->name('relatorios.pdf');
    Route::get('/profile', [AccountController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [AccountController::class, 'update'])->name('profile.update');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
    Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
    Route::put('/leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
    Route::post('/leads/{lead}/schedule', [LeadController::class, 'schedule'])->name('leads.schedule');
    Route::get('/agendamentos', [AgendamentoController::class, 'index'])->name('agendamentos.index');
    Route::post('/agendamentos', [AgendamentoController::class, 'store'])->name('agendamentos.store');
    Route::put('/agendamentos/{agendamento}', [AgendamentoController::class, 'update'])->name('agendamentos.update');
    Route::post('/agendamentos/{agendamento}/convert', [AgendamentoController::class, 'convert'])->name('agendamentos.convert');
    Route::delete('/agendamentos/{agendamento}', [AgendamentoController::class, 'destroy'])->name('agendamentos.destroy');
    Route::get('/visitas', [VisitaController::class, 'index'])->name('visitas.index');
    Route::post('/visitas', [VisitaController::class, 'store'])->name('visitas.store');
    Route::put('/visitas/{visita}', [VisitaController::class, 'update'])->name('visitas.update');
    Route::patch('/visitas/{visita}/status', [VisitaController::class, 'updateStatus'])->name('visitas.status');
    Route::post('/visitas/{visita}/matriculas', [VisitaController::class, 'enroll'])->name('visitas.enroll');
    Route::post('/visitas/{visita}/matriculas/cancel', [VisitaController::class, 'cancelEnrollment'])->name('visitas.enrollments.cancel');
    Route::delete('/visitas/{visita}', [VisitaController::class, 'destroy'])->name('visitas.destroy');
    Route::get('/turmas', [TurmaController::class, 'index'])->name('turmas.index');
    Route::get('/turmas/{turma}', [TurmaController::class, 'show'])->name('turmas.show');
    Route::get('/vendedores', [VendedorController::class, 'index'])->name('vendedores.index');
    Route::get('/painel-vendas', [VendedorController::class, 'sales'])->name('painel-vendas');

    Route::middleware('role:master,gestor')->group(function () {
        Route::post('/turmas', [TurmaController::class, 'store'])->name('turmas.store');
        Route::post('/turmas/criar-em-lote', [TurmaController::class, 'bulkStore'])->name('turmas.bulk-store');
        Route::put('/turmas/{turma}', [TurmaController::class, 'update'])->name('turmas.update');
        Route::delete('/turmas/{turma}', [TurmaController::class, 'destroy'])->name('turmas.destroy');

        Route::post('/campanhas', [CampanhaController::class, 'store'])->name('campanhas.store');
        Route::put('/campanhas/{campanha}', [CampanhaController::class, 'update'])->name('campanhas.update');
        Route::post('/campanhas/{campanha}/metas', [CampanhaController::class, 'storeSellerGoal'])->name('campanhas.metas.store');
    });
    Route::middleware('role:master')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/legacy-import', [LegacyLeadImportController::class, 'index'])->name('legacy-import.index');
        Route::post('/legacy-import', [LegacyLeadImportController::class, 'store'])->name('legacy-import.store');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/active', [UserController::class, 'setActive'])->name('users.active');

        Route::get('/units', [UnidadeController::class, 'index'])->name('units.index');
        Route::post('/units', [UnidadeController::class, 'store'])->name('units.store');
        Route::put('/units/{unidade}', [UnidadeController::class, 'update'])->name('units.update');
        Route::patch('/units/{unidade}/active', [UnidadeController::class, 'setActive'])->name('units.active');
    });
});
