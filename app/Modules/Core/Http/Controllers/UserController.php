<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Models\User;
use App\Modules\Core\Http\Requests\StoreUserRequest;
use App\Modules\Core\Http\Requests\UpdateUserRequest;
use App\Modules\Core\Services\CompanyUserService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Administración de los usuarios de la empresa activa y su rol. La lógica de asignación de roles
 * (contexto de equipo de spatie) vive en CompanyUserService.
 *
 * Los usuarios NO llevan el CompanyScope global, así que el aislamiento por empresa se aplica
 * explícitamente aquí: cada acción confirma que el usuario pertenece a la empresa activa.
 */
final class UserController extends Controller
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function store(StoreUserRequest $request, CompanyUserService $users): RedirectResponse
    {
        $companyId = $this->currentCompany->id();
        abort_if($companyId === null, 403);

        $data = $request->validated();
        $users->create($companyId, $data, $data['role']);

        return back()->with('panel_ok', 'Usuario creado correctamente.');
    }

    public function update(UpdateUserRequest $request, User $user, CompanyUserService $users): RedirectResponse
    {
        $this->authorizeCompany($user);

        $users->update($user, $request->validated());

        return back()->with('panel_ok', 'Usuario actualizado.');
    }

    public function toggle(User $user, CompanyUserService $users): RedirectResponse
    {
        $this->authorizeCompany($user);

        // No puedes desactivarte a ti mismo: te dejaría fuera de tu propia sesión.
        if ($user->is($this->currentUser())) {
            return back()->with('panel_error', 'No puedes desactivar tu propia cuenta.');
        }

        $users->setActive($user, ! $user->is_active);

        return back()->with('panel_ok', $user->is_active ? 'Usuario reactivado.' : 'Usuario desactivado.');
    }

    /**
     * Un usuario de otra empresa (o el super admin sin empresa) no es administrable desde aquí.
     */
    private function authorizeCompany(User $user): void
    {
        abort_if($user->is_super_admin, 403);
        abort_unless((int) $user->company_id === $this->currentCompany->id(), 404);
    }

    private function currentUser(): ?User
    {
        $user = request()->user();

        return $user instanceof User ? $user : null;
    }
}
