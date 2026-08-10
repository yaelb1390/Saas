<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alta y edición de categorías de producto.
 *
 * El `slug` no se pide en el formulario: se deriva del nombre. Al ser único por empresa, se le añade
 * un sufijo numérico si ya existe (dos empresas pueden tener «Helados», pero una sola vez cada una).
 */
final class CategoryService
{
    public function create(string $name, ?int $parentId = null, ?string $icon = null): Category
    {
        return Category::create([
            'name' => $name,
            'parent_id' => $parentId,
            'icon' => $icon,
            'slug' => $this->uniqueSlug($name),
            'is_active' => true,
        ]);
    }

    /**
     * @param  array{name: string, parent_id?: int|null, icon?: string|null, is_active?: bool}  $data
     */
    public function update(Category $category, array $data): Category
    {
        // El slug solo se regenera si cambia el nombre: así no se rompen enlaces ya compartidos.
        if ($data['name'] !== $category->name) {
            $data['slug'] = $this->uniqueSlug($data['name'], $category->id);
        }

        $category->update($data);

        return $category;
    }

    /**
     * Borra la categoría y deja sus productos sin categoría.
     *
     * El desligado hay que hacerlo a mano: la clave foránea está declarada `nullOnDelete`, pero el
     * modelo usa borrado en suave, así que la fila permanece y la base nunca dispara esa regla. Sin
     * esto los productos quedarían apuntando a una categoría invisible y desaparecerían de la
     * rejilla del punto de venta sin que nadie los hubiera tocado.
     */
    public function delete(Category $category): void
    {
        DB::transaction(function () use ($category): void {
            $category->products()->update(['category_id' => null]);
            $category->delete();
        });
    }

    /**
     * Slug libre dentro de la empresa activa. El scope de empresa ya acota la consulta, así que el
     * sufijo solo crece frente a colisiones reales del mismo negocio.
     */
    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'categoria';
        $slug = $base;
        $suffix = 2;

        while (Category::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
