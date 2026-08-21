<?php

declare(strict_types=1);

namespace App\Modules\Social\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Social\Enums\SocialPlatform;
use App\Modules\Social\Exceptions\SocialException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Hablar con Zernio, que publica en las redes del cliente.
 *
 * LA CLAVE ES DE CADA EMPRESA, no de la plataforma. Eso es una decisión de negocio —cada cliente
 * contrata Zernio por su cuenta— y tiene una consecuencia técnica que simplifica mucho esto: la
 * cuenta de Zernio del cliente YA ES su aislamiento. No hace falta crear perfiles ni mapear cuál es
 * de quién, porque con su clave solo se ven sus cosas. Si mañana se pasara a una sola clave de
 * plataforma, habría que meter perfiles por empresa y todo el enredo de enrutar avisos.
 *
 * NO SE GUARDA COPIA de cuentas ni publicaciones. Zernio es el registro; duplicarlo aquí crearía dos
 * verdades que se separan en cuanto alguien publique desde el móvil o borre un post desde la app de
 * Instagram. La pantalla pregunta y muestra lo que hay. Cuesta una llamada por carga y a cambio
 * nunca miente.
 */
final class ZernioClient
{
    private const BASE = 'https://api.zernio.com';

    /** Un negocio publicando en sus redes no espera más que esto; pasado el plazo, mejor decirlo. */
    private const TIMEOUT = 20;

    public function __construct(private readonly Company $company) {}

    public function isConfigured(): bool
    {
        return filled($this->company->social_api_key);
    }

    /**
     * Dirección a la que mandar al cliente para autorizar una red.
     *
     * El enlace lo emite Zernio y caduca: se pide en el momento de pulsar, no se guarda.
     *
     * `profileId` es OBLIGATORIO —la documentación lo daba por opcional y la API lo rechaza con
     * «missing_required_field»—, así que se resuelve antes. Con clave por empresa solo hay un perfil,
     * el que Zernio crea al abrir la cuenta, pero hay que nombrarlo igualmente.
     */
    public function connectUrl(SocialPlatform $platform): string
    {
        $respuesta = $this->http()->get(self::BASE."/v1/connect/{$platform->value}", [
            'profileId' => $this->defaultProfileId(),
        ]);

        $url = $respuesta->json('authUrl');

        if (! $respuesta->successful() || ! is_string($url) || $url === '') {
            $this->registrar('no se pudo abrir la conexión', $platform->value, $respuesta->status(), $respuesta->body());

            throw SocialException::noSePudoConectar($platform->label());
        }

        return $url;
    }

    /**
     * El perfil donde aterrizan las cuentas de esta empresa.
     *
     * Con clave por empresa solo hay uno —el que Zernio crea al abrir la cuenta— pero la API lo exige
     * igualmente al conectar una red. Se toma el marcado por omisión, y si no lo hubiera, el primero:
     * fallar por no encontrar «el de por omisión» sería absurdo cuando solo hay uno.
     */
    private function defaultProfileId(): string
    {
        $respuesta = $this->http()->get(self::BASE.'/v1/profiles');

        $perfiles = (array) $respuesta->json('profiles', []);

        if (! $respuesta->successful() || $perfiles === []) {
            $this->registrar('no se pudo leer el perfil', null, $respuesta->status(), $respuesta->body());

            throw SocialException::noRespondio();
        }

        foreach ($perfiles as $perfil) {
            if (($perfil['isDefault'] ?? false) === true) {
                return (string) $perfil['_id'];
            }
        }

        return (string) $perfiles[0]['_id'];
    }

    /**
     * Las cuentas que la empresa tiene conectadas, con los nombres que usa esta aplicación.
     *
     * SE TRADUCEN los campos: la API devuelve `displayName` y `profilePicture`, no `name` ni `avatar`
     * como decía la documentación. Se normaliza aquí y no en la vista para que el día que cambien
     * haya UN sitio que tocar, y porque una plantilla no debería saber cómo llama Zernio a las cosas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function accounts(): array
    {
        $respuesta = $this->http()->get(self::BASE.'/v1/accounts');

        if (! $respuesta->successful()) {
            $this->registrar('no se pudieron leer las cuentas', null, $respuesta->status(), $respuesta->body());

            throw SocialException::noRespondio();
        }

        return array_map(static fn (array $c): array => [
            'id' => (string) ($c['_id'] ?? ''),
            'platform' => (string) ($c['platform'] ?? ''),
            // El nombre visible; si faltara, el usuario de la red, que siempre está.
            'name' => (string) ($c['displayName'] ?? $c['username'] ?? 'Sin nombre'),
            'username' => $c['username'] ?? null,
            'avatar' => $c['profilePicture'] ?? null,
            'url' => $c['profileUrl'] ?? null,
            'followers' => $c['followersCount'] ?? null,
            // Una cuenta caducada NO da error al publicar: se traga el post y no sale nada. Por eso
            // se saca a la superficie en vez de dejar que el dueño lo descubra días después.
            'necesita_reconectar' => ($c['needsReconnection'] ?? false) === true
                || ($c['isActive'] ?? true) === false
                || ($c['enabled'] ?? true) === false,
        ], (array) $respuesta->json('accounts', []));
    }

    /**
     * Las respuestas automáticas de la empresa, con lo que llevan hecho.
     *
     * Las estadísticas vienen en la misma respuesta: cuántas veces se disparó, cuántos mensajes
     * salieron y a cuánta gente distinta. No cuesta una llamada extra y es lo que responde a la única
     * pregunta que importa: ¿esto está sirviendo de algo?
     *
     * @return array<int, array<string, mixed>>
     */
    public function automations(): array
    {
        $respuesta = $this->http()->get(self::BASE.'/v1/comment-automations');

        if (! $respuesta->successful()) {
            $this->registrar('no se pudieron leer las automatizaciones', null, $respuesta->status(), $respuesta->body());

            throw SocialException::noRespondio();
        }

        return array_map(static fn (array $a): array => [
            'id' => (string) ($a['id'] ?? ''),
            'name' => (string) ($a['name'] ?? 'Sin nombre'),
            'platform' => (string) ($a['platform'] ?? ''),
            'accountId' => (string) ($a['accountId'] ?? ''),
            'keywords' => (array) ($a['keywords'] ?? []),
            'matchMode' => (string) ($a['matchMode'] ?? 'contains'),
            // Qué la dispara. Se lee de vuelta porque, sin esto, abrir una automatización de
            // HISTORIA y guardarla sin tocar nada la convertiría en una de comentarios: el
            // formulario mandaría el valor por omisión. Mismo fallo que ya tuvimos con las
            // variaciones y con la tolerancia a erratas.
            'trigger' => (string) ($a['trigger'] ?? 'comment'),
            // Cuándo se creó. Se lee porque es lo que decide QUIÉN RESPONDE cuando dos
            // automatizaciones de la misma cuenta comparten palabras y ámbito: la más antigua se
            // queda con todos los comentarios y la nueva no ve ninguno. Visto en la cuenta real: la
            // del 16 de agosto tenía seis registros y la del 20, cero.
            'createdAt' => (string) ($a['createdAt'] ?? ''),
            'commentReply' => $a['commentReply'] ?? null,
            'dmMessage' => (string) ($a['dmMessage'] ?? ''),
            // Las versiones alternativas, para poder editarlas. Sin esto, abrir una automatización
            // y guardarla sin tocar nada le borraría las variaciones sin decir nada.
            'dmMessageVariations' => array_values((array) ($a['dmMessageVariations'] ?? [])),
            'commentReplyVariations' => array_values((array) ($a['commentReplyVariations'] ?? [])),
            // Ausente significa «al instante»: la API lo omite cuando no hay espera.
            'dmDelaySeconds' => (int) ($a['dmDelaySeconds'] ?? 0),
            'buttons' => (array) ($a['buttons'] ?? []),
            'alsoMatchInDms' => ($a['alsoMatchInDms'] ?? false) === true,
            // Se lee para poder editarla: si no viajara de vuelta, abrir una automatización que la
            // tuviera puesta y guardarla sin tocar nada se la quitaría en silencio.
            'typoTolerance' => ($a['typoTolerance'] ?? false) === true,
            // Comparación estricta y NO `filled()`: `filled(false)` vale true —solo el nulo y la
            // cadena vacía son «vacíos»—, así que la pantalla anunciaba «solo a quien te siga» en
            // automatizaciones que respondían a cualquiera.
            'followGate' => ($a['followGate'] ?? false) === true,
            'isActive' => ($a['isActive'] ?? false) === true,
            // Sin publicación concreta = vale para cualquiera. Es lo normal y conviene decirlo.
            'postId' => $a['platformPostId'] ?? null,
            /*
             * Las NUEVE cifras que manda el servidor, no las cuatro que se enseñaban.
             *
             * Tres avisos de la especificación que son trampas, y por eso viajan con nombre propio:
             *
             *  · `trackedSends` es el DENOMINADOR del porcentaje de clics, no `dmsSent`. Con 100
             *    enviados, 40 medibles y 10 clics, la verdad es 25 % y no 10 %.
             *  · `delivered` NO existe en Instagram —«IG emits no delivery receipt»—, así que ahí es
             *    cero estructural y no un desastre.
             *  · `read` sí vale en las dos redes.
             */
            'stats' => [
                'triggered' => (int) ($a['stats']['triggered'] ?? 0),
                'sent' => (int) ($a['stats']['dmsSent'] ?? 0),
                'failed' => (int) ($a['stats']['dmsFailed'] ?? 0),
                'people' => (int) ($a['stats']['uniqueContacts'] ?? 0),
                'medibles' => (int) ($a['stats']['trackedSends'] ?? 0),
                'clics' => (int) ($a['stats']['linkClicks'] ?? 0),
                'clicaron' => (int) ($a['stats']['uniqueClicks'] ?? 0),
                'entregados' => (int) ($a['stats']['delivered'] ?? 0),
                'leidos' => (int) ($a['stats']['read'] ?? 0),
            ],
        ], (array) $respuesta->json('automations', []));
    }

    /**
     * Crea una respuesta automática.
     *
     * `profileId` va SIEMPRE: la API lo exige y ya mordió una vez al conectar cuentas, donde el manual
     * lo daba por opcional. Los campos que no se ofrecen en el formulario —variaciones, retrasos,
     * palabras excluidas— simplemente no se mandan; la API los acepta ausentes.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function createAutomation(array $datos): array
    {
        $respuesta = $this->http()->post(self::BASE.'/v1/comment-automations', [
            'profileId' => $this->defaultProfileId(),
        ] + $datos);

        if (! $respuesta->successful()) {
            $this->registrar('no se pudo crear la automatización', null, $respuesta->status(), $respuesta->body());

            throw SocialException::noSeGuardoLaAutomatizacion($respuesta->json('message') ?? $respuesta->json('error'));
        }

        $creada = (array) $respuesta->json('automation', []);

        /*
         * ZERNIO IGNORA `isActive: false` AL CREAR: nace encendida se pida lo que se pida. Comprobado
         * contra la API de verdad —se mandó apagada y respondió `isActive: true`—.
         *
         * Sin esto, quien desmarque «Encendida» creería que guardó algo dormido y estaría contestando
         * a sus seguidores desde el primer comentario. Se apaga en un segundo paso, que es lo único
         * que la API respeta.
         */
        if (($datos['isActive'] ?? true) === false && ($creada['isActive'] ?? false) === true) {
            $this->updateAutomation((string) $creada['id'], ['isActive' => false]);
            $creada['isActive'] = false;
        }

        return $creada;
    }

    /**
     * Cambia una automatización existente. `PATCH`, no `PUT`: se manda solo lo que cambia.
     *
     * @param  array<string, mixed>  $datos
     */
    public function updateAutomation(string $id, array $datos): void
    {
        $respuesta = $this->http()->patch(self::BASE."/v1/comment-automations/{$id}", $datos);

        if (! $respuesta->successful()) {
            $this->registrar('no se pudo guardar la automatización', null, $respuesta->status(), $respuesta->body());

            throw SocialException::noSeGuardoLaAutomatizacion($respuesta->json('message') ?? $respuesta->json('error'));
        }
    }

    public function deleteAutomation(string $id): void
    {
        $respuesta = $this->http()->delete(self::BASE."/v1/comment-automations/{$id}");

        if (! $respuesta->successful()) {
            $this->registrar('no se pudo borrar la automatización', null, $respuesta->status(), $respuesta->body());

            throw SocialException::noRespondio();
        }
    }

    /**
     * Los disparos de una automatización, con su paginación y —lo importante— los escapes.
     *
     * Es la única respuesta a «lo monté y no hace nada». Y `misses` es la única respuesta a «¿qué
     * palabra me falta?»: son los comentarios que llegaron y no coincidieron con ninguna palabra
     * clave. La propia especificación dice que es «the only signal that a keyword is catching
     * nothing», y hasta ahora se tiraba a la basura junto con la paginación.
     *
     * Devuelve SIEMPRE la misma forma, también cuando falla, para que la vista no tenga que
     * comprobar nulos por su cuenta. El registro es una ayuda, no el contenido principal: si Zernio
     * no responde, la pantalla se pinta igual con los contadores que ya trae el listado.
     *
     * El plazo baja a 10 s a propósito. Esta página hace dos llamadas a Zernio y con los 20 s de
     * siempre el peor caso serían 40 s, que en producción agota el tiempo de la función.
     *
     * @param  string|null  $status  pending|sent|failed|skipped|gated
     * @return array{logs: array<int, array<string, mixed>>, pagination: array{total: int, hasMore: bool}, misses: array<string, mixed>|null}
     */
    public function automationLogs(string $id, int $limit = 50, ?string $status = null): array
    {
        $vacio = ['logs' => [], 'pagination' => ['total' => 0, 'hasMore' => false], 'misses' => null];

        // 200 es el tope de la API; pedir más devuelve un error en vez de recortar.
        $consulta = ['limit' => max(1, min($limit, 200))];

        if ($status !== null) {
            $consulta['status'] = $status;
        }

        $respuesta = $this->http()->timeout(10)->get(self::BASE."/v1/comment-automations/{$id}/logs", $consulta);

        if (! $respuesta->successful()) {
            $this->registrar('no se pudo leer el registro', null, $respuesta->status(), $respuesta->body());

            return $vacio;
        }

        $logs = (array) ($respuesta->json('logs') ?? []);

        return [
            'logs' => $logs,
            'pagination' => [
                // Sin paginación, el total es lo que haya venido: es lo que permite saber si el
                // registro está completo, y de eso depende que se pueda dibujar una gráfica honesta.
                'total' => (int) ($respuesta->json('pagination.total') ?? count($logs)),
                'hasMore' => ($respuesta->json('pagination.hasMore') ?? false) === true,
            ],
            'misses' => $respuesta->json('misses'),
        ];
    }

    /**
     * Las publicaciones ya publicadas, listas para colgarles una respuesta automática.
     *
     * Una publicación de Zernio puede haber salido en varias redes, y CADA RED tiene su propio
     * identificador. Aquí se aplana a una entrada por red, porque una automatización se cuelga de una
     * publicación concreta de una cuenta concreta, no de «el post» en abstracto.
     *
     * Se descartan las que aún no tienen identificador de plataforma: son borradores o programadas,
     * y todavía no existen en Instagram como para comentarlas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function publishedPosts(int $limit = 50): array
    {
        $respuesta = $this->http()->get(self::BASE.'/v1/posts', ['limit' => $limit]);

        if (! $respuesta->successful()) {
            $this->registrar('no se pudieron leer las publicaciones', null, $respuesta->status(), $respuesta->body());

            return [];
        }

        $salida = [];

        foreach ((array) $respuesta->json('posts', []) as $post) {
            foreach ((array) ($post['platforms'] ?? []) as $destino) {
                if (blank($destino['platformPostId'] ?? null)) {
                    continue;
                }

                $salida[] = [
                    // Los DOS identificadores: la API exige `postId` (el suyo) cuando se apunta a una
                    // publicación concreta con `platformPostId` (el de la red).
                    'postId' => (string) ($post['_id'] ?? ''),
                    'platformPostId' => (string) $destino['platformPostId'],
                    'accountId' => (string) ($destino['accountId']['_id'] ?? $destino['accountId'] ?? ''),
                    'platform' => (string) ($destino['platform'] ?? ''),
                    'url' => $destino['platformPostUrl'] ?? null,
                    // Un trozo del texto: es como el dueño reconoce cuál es, no por su identificador.
                    'title' => str((string) ($post['content'] ?? $post['title'] ?? 'Sin texto'))->limit(60)->toString(),
                    'publishedAt' => $destino['publishedAt'] ?? $post['publishedAt'] ?? null,
                ];
            }
        }

        return $salida;
    }

    /**
     * Trae de la red las publicaciones que NO se hicieron desde aquí.
     *
     * Hace falta porque `/v1/posts` solo conoce lo publicado a través de Zernio, y la mayoría de un
     * negocio pequeño se sube desde el móvil. Sin esto, «colgar la automatización de esta foto» solo
     * funcionaría con fotos subidas desde el panel, que es justo la minoría.
     *
     * Solo LEE de la red: no publica nada.
     */
    public function syncExternalPosts(string $accountId): int
    {
        $respuesta = $this->http()
            // La red puede tardar: se le da margen antes de rendirse.
            ->timeout(60)
            ->post(self::BASE.'/v1/posts/sync-external', ['accountId' => $accountId]);

        if (! $respuesta->successful()) {
            $this->registrar('no se pudieron traer las publicaciones', null, $respuesta->status(), $respuesta->body());

            throw SocialException::noRespondio();
        }

        return (int) ($respuesta->json('synced.postsFound') ?? 0);
    }

    /**
     * Las últimas publicaciones, con su estado real.
     *
     * @return array<int, array<string, mixed>>
     */
    public function posts(int $limit = 20): array
    {
        $respuesta = $this->http()->get(self::BASE.'/v1/posts', ['limit' => $limit]);

        if (! $respuesta->successful()) {
            $this->registrar('no se pudieron leer las publicaciones', null, $respuesta->status(), $respuesta->body());

            throw SocialException::noRespondio();
        }

        return (array) $respuesta->json('posts', []);
    }

    /**
     * Publica ahora o programa para más tarde.
     *
     * `scheduledFor` viaja con su zona horaria: sin ella, «mañana a las 8» se publicaría a las 4 de
     * la madrugada hora de aquí, que es exactamente cuando nadie lo va a ver.
     *
     * @param  array<int, array{platform: string, accountId: string}>  $targets
     * @param  array<int, array{type: string, url: string}>  $media
     * @return array<string, mixed>
     */
    public function publish(string $content, array $targets, ?string $scheduledFor = null, array $media = []): array
    {
        if ($targets === []) {
            throw SocialException::sinCuentas();
        }

        $cuerpo = [
            'content' => $content,
            'platforms' => $targets,
        ];

        if ($media !== []) {
            $cuerpo['mediaItems'] = $media;
        }

        if ($scheduledFor === null) {
            $cuerpo['publishNow'] = true;
        } else {
            $cuerpo['scheduledFor'] = $scheduledFor;
            $cuerpo['timezone'] = config('app.timezone');
        }

        $respuesta = $this->http()->post(self::BASE.'/v1/posts', $cuerpo);

        if (! $respuesta->successful()) {
            $this->registrar('no se pudo publicar', null, $respuesta->status(), $respuesta->body());

            // El motivo de Zernio se enseña tal cual cuando lo hay: «el vídeo excede la duración de
            // TikTok» es accionable, y «no se pudo publicar» no.
            // `error` ADEMÁS de `message`: los rechazos de verdad llegan en `error` —«Instagram posts
            // require media content»— y leyendo solo `message` se perdía el único dato accionable,
            // dejando al dueño con un «revisa el texto» que no dice qué revisar.
            throw SocialException::noSePudoPublicar($respuesta->json('message') ?? $respuesta->json('error'));
        }

        return (array) $respuesta->json();
    }

    /**
     * Permiso para subir una imagen DIRECTAMENTE desde el navegador.
     *
     * El archivo no pasa por nuestro servidor, y eso no es una optimización: en producción el disco
     * es de solo lectura y no hay ni GD ni Imagick. Reenviar la foto por aquí sería código que solo
     * funciona en local.
     *
     * @return array{uploadUrl: string, publicUrl: string}
     */
    public function presignMedia(string $fileName, string $contentType): array
    {
        $respuesta = $this->http()->post(self::BASE.'/v1/media/presign', [
            // `filename` TODO EN MINÚSCULA. Comprobado contra la API: con `fileName` responde 400
            // «missing_required_field, param: filename». Es la tercera vez que el manual y el
            // servidor no coinciden en un nombre de campo, después de `displayName` y `profileId`.
            'filename' => $fileName,
            'contentType' => $contentType,
        ]);

        $subida = $respuesta->json('uploadUrl');
        $publica = $respuesta->json('publicUrl');

        if (! $respuesta->successful() || ! is_string($subida) || ! is_string($publica)) {
            $this->registrar('no se pudo preparar la subida', null, $respuesta->status(), $respuesta->body());

            throw SocialException::noSePudoSubirLaFoto();
        }

        return ['uploadUrl' => $subida, 'publicUrl' => $publica];
    }

    /**
     * Manda un privado dentro de una conversación que YA existe.
     *
     * Solo se puede contestar, nunca escribir el primero: Instagram exige que la ventana la haya
     * abierto la persona. Por eso el parámetro es una conversación y no un usuario — la API no
     * ofrece otra cosa, y no es un descuido suyo.
     *
     * La `Idempotency-Key` es la segunda red: la primera es nuestra tabla `social_welcomes`, pero si
     * dos avisos llegaran a la vez y las dos peticiones cruzaran la comprobación, esto evita que
     * salgan dos mensajes. Zernio las retiene 24 h.
     */
    public function enviarMensaje(string $conversationId, string $accountId, string $texto, ?string $idempotencia = null): void
    {
        $peticion = $this->http();

        if ($idempotencia !== null) {
            $peticion = $peticion->withHeaders(['Idempotency-Key' => $idempotencia]);
        }

        $respuesta = $peticion->post(
            self::BASE.'/v1/inbox/conversations/'.rawurlencode($conversationId).'/messages',
            ['accountId' => $accountId, 'message' => $texto],
        );

        if (! $respuesta->successful()) {
            $this->registrar('no se pudo enviar el mensaje', null, $respuesta->status(), $respuesta->body());

            throw SocialException::noRespondio();
        }
    }

    /**
     * Da de alta el webhook por el que Zernio nos avisa de los mensajes entrantes.
     *
     * Devuelve su identificador para poder darlo de baja después: sin guardarlo, apagar la
     * bienvenida dejaría el webhook vivo en Zernio disparando contra una URL que ya rechaza todo, y
     * cada mensaje del cliente generaría un error en su panel.
     */
    public function registrarWebhook(string $url, string $secreto): ?string
    {
        $respuesta = $this->http()->post(self::BASE.'/v1/webhooks/settings', [
            'name' => 'BM Business — bienvenida',
            'url' => $url,
            'secret' => $secreto,
            // Solo este. `conversation.started` NO sirve: se dispara «in either direction» y su
            // aviso no dice quién empezó, así que las conversaciones que abren nuestras propias
            // respuestas automáticas también lo dispararían y el cliente recibiría dos mensajes.
            'events' => ['message.received'],
        ]);

        if (! $respuesta->successful()) {
            $this->registrar('no se pudo registrar el webhook', null, $respuesta->status(), $respuesta->body());

            throw SocialException::noRespondio();
        }

        $id = $respuesta->json('webhook._id') ?? $respuesta->json('_id') ?? $respuesta->json('id');

        return is_string($id) ? $id : null;
    }

    /**
     * Da de baja el webhook.
     *
     * No lanza: apagar la bienvenida tiene que funcionar aunque Zernio no conteste. Quedarse con un
     * webhook huérfano es molesto; no poder apagar los mensajes que salen en tu nombre, no.
     */
    public function borrarWebhook(string $webhookId): bool
    {
        try {
            $respuesta = $this->http()->delete(self::BASE.'/v1/webhooks/settings', ['id' => $webhookId]);
        } catch (\Throwable $e) {
            $this->registrar('no se pudo borrar el webhook: '.$e->getMessage(), null, 0, '');

            return false;
        }

        if (! $respuesta->successful()) {
            $this->registrar('no se pudo borrar el webhook', null, $respuesta->status(), $respuesta->body());
        }

        return $respuesta->successful();
    }

    private function http(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw SocialException::sinClave();
        }

        return Http::withToken((string) $this->company->social_api_key)
            ->acceptJson()
            ->asJson()
            ->timeout(self::TIMEOUT);
    }

    /**
     * Deja constancia del motivo SIN la clave ni el cuerpo entero.
     *
     * Los fallos aquí suelen ser concretos y arreglables —clave caducada, cuenta desconectada, vídeo
     * demasiado largo— y sin registro habría que adivinarlos. Se recorta la respuesta porque puede
     * traer el post completo, y eso llenaría el registro de texto del cliente.
     */
    private function registrar(string $que, ?string $plataforma, int $estado, string $cuerpo): void
    {
        $detalle = [
            'empresa' => $this->company->id,
            'plataforma' => $plataforma,
            'estado' => $estado,
            'respuesta' => mb_substr($cuerpo, 0, 300),
        ];

        Log::warning("Zernio: {$que}", $detalle);

        /*
         * Y además a la tabla, que es lo único que sobrevive.
         *
         * En Vercel el log va a stderr con la retención que tenga Vercel y sin forma de buscar: para
         * cuando un cliente dice «no me publicó», ya no hay nada que mirar. Se conservan los dos
         * porque en el despliegue con Docker el log sí sirve, y son dos líneas.
         */
        SystemEvent::registrar(
            type: 'integration.failed',
            message: "Zernio: {$que}",
            contexto: $detalle,
            // Grave solo cuando la culpa es de la credencial o del permiso: eso no se arregla
            // reintentando y alguien tiene que entrar a mirarlo hoy.
            level: in_array($estado, [401, 403], true) ? SystemEvent::GRAVE : SystemEvent::AVISO,
            companyId: (int) $this->company->id,
        );
    }
}
