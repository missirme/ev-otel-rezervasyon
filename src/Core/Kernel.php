<?php

declare(strict_types=1);

namespace App\Core;

use App\Exception\BusyException;
use App\Exception\ConflictException;
use App\Exception\ForbiddenException;
use App\Exception\InvalidTransitionException;
use App\Exception\MailDeliveryException;
use App\Exception\NotFoundException;
use App\Exception\StorageUnavailableException;
use App\Exception\ValidationException;
use App\Model\Role;
use App\Seed\DemoSeeder;
use Throwable;

/**
 * Ön denetleyicinin çekirdeği: her istek aynı yoldan geçer
 * public/index.php -> Kernel -> Router -> Controller -> Service -> Repository.
 *
 * Burada her istek için: oturum, oturum zaman aşımı, CSRF ve rota bazlı yetki denetimi yapılır.
 */
final class Kernel
{
    /** İstek /api/ ile başlıyorsa yanıtlar (hatalar dahil) JSON'dur. */
    private bool $api = false;

    /** İstek /parca/ ile başlıyorsa yanıt bir sayfa bölümüdür (kısmi yenileme); hata da tam sayfa değil, kısa bir bölüm olarak döner. */
    private bool $fragment = false;

    public function __construct(private readonly Container $container)
    {
    }

    public function handle(Request $request): Response
    {
        $view = $this->container->view($request->basePath);
        $session = $this->container->session();
        $auth = $this->container->auth();
        $logger = $this->container->logger();
        $this->api = str_starts_with($request->path, '/api/');
        $this->fragment = str_starts_with($request->path, '/parca/');

        try {
            $this->ensureDemoData();
            $session->start($request->isHttps());
            $now = $this->container->clock->now()->getTimestamp();

            // Bonus: uzun süre işlem yapılmayan oturum kapatılır.
            if ($auth->check() && $session->isTimedOut($now)) {
                $auth->logout();
                $session->start($request->isHttps());
                $session->flash('warning', 'Oturumunuz uzun süre işlem yapılmadığı için kapatıldı. Lütfen tekrar giriş yapın.');
                if ($this->isBackground($request)) {
                    // Arka plan isteğine giriş sayfası gönderilmez; uyarı, kullanıcının açacağı ilk sayfada gösterilir.
                    return $this->secure($this->loginRequired());
                }

                return $this->secure(Response::redirect($view->url('/giris')));
            }
            // Kendiliğinden yenilenen sayfa bölümleri (/parca/...) kullanıcı işlemi sayılmaz;
            // yoksa açık bırakılan bir sekme oturumu canlı tutar ve zaman aşımı hiç çalışmaz.
            if (!$this->fragment) {
                $session->touch($now);
            }

            $view->share('csrfToken', $this->container->csrf()->token());
            $view->share('currentUser', $auth->user());
            $view->share('currentPath', $request->path);
            $view->share('appName', $this->container->settings->appName);
            // FR-04: e-posta modu ve (yalnızca bu bilgisayardan) simüle posta kutusu bağlantısı
            $view->share('mailMode', $this->container->settings->mailDriver);
            $view->share('mailInbox', $this->container->mailbox()->enabled() && $request->isFromLoopback());

            // Bozuk kodlanmış (UTF-8 olmayan) girdi veri dosyasına yazılamaz; denetleyiciye ulaşmadan reddedilir.
            if ($request->hasInvalidEncoding()) {
                return $this->secure($this->error($view, 400, 'Geçersiz istek', 'Gönderilen bilgilerde okunamayan karakterler var. Lütfen alanları yeniden yazıp tekrar deneyin.'));
            }

            $match = $this->container->router()->match($request->method, $request->path);
            if ($match === null) {
                throw new NotFoundException('Aradığınız sayfa bulunamadı.');
            }
            if ($match === 'method') {
                return $this->secure($this->error($view, 405, 'İzin verilmeyen işlem', 'Bu adres bu yöntemle kullanılamaz.'));
            }

            // Durum değiştiren her POST isteği CSRF jetonu taşımalıdır (NFR-14).
            if ($request->isPost() && !$this->container->csrf()->verify($request->input('_csrf'))) {
                return $this->secure($this->error($view, 419, 'Güvenlik doğrulaması başarısız', 'Form süresi dolmuş olabilir. Lütfen sayfayı yenileyip tekrar deneyin.'));
            }

            // Rota bazlı yetki (FR-05): arayüzde gizli olsa bile doğrudan istek de reddedilir.
            $access = $match['access'];
            if ($access !== null && !$auth->check()) {
                if ($this->isBackground($request)) {
                    return $this->secure($this->loginRequired());
                }
                $session->flash('info', 'Bu sayfa için giriş yapmalısınız.');

                return $this->secure(Response::redirect($view->url('/giris', ['donus' => $request->fullPath()])));
            }
            if (is_array($access) && !$auth->hasRole(...$access)) {
                throw new ForbiddenException('Bu sayfaya erişim yetkiniz yok.');
            }

            [$class, $method] = $match['handler'];
            $controller = $this->container->controller($class);
            /** @var Response $response */
            $response = $controller->{$method}($request, ...$match['params']);

            return $this->secure($response);
        } catch (NotFoundException $e) {
            return $this->secure($this->error($view, 404, 'Bulunamadı', $e->getMessage()));
        } catch (ForbiddenException $e) {
            return $this->secure($this->error($view, 403, 'Yetkiniz yok', $e->getMessage()));
        } catch (ValidationException $e) {
            // Denetleyicide karşılanmamış doğrulama hatası "beklenmeyen hata" değildir; mesajı kullanıcıya gösterilir.
            return $this->secure($this->error($view, 422, 'Geçersiz istek', $e->getMessage()));
        } catch (ConflictException | InvalidTransitionException $e) {
            // Aynı anda yapılan iki işlemden geç kalanı: tarih az önce doldu ya da durum az önce değişti.
            return $this->secure($this->error($view, 409, 'İşlem tamamlanamadı', $e->getMessage()));
        } catch (BusyException $e) {
            return $this->secure($this->error($view, 503, 'Yoğunluk', $e->getMessage()));
        } catch (MailDeliveryException $e) {
            // İleti yazılamadıysa kullanıcıya "gönderildi" denmez; ayrıntı (klasör yolu) yalnızca günlüğe yazılır.
            $logger->error('eposta_gonderilemedi', ['mesaj' => $e->getMessage()]);

            return $this->secure($this->error($view, 503, 'E-posta gönderilemedi', 'Sıfırlama e-postası şu anda gönderilemiyor. Lütfen biraz sonra tekrar deneyin.'));
        } catch (StorageUnavailableException $e) {
            $logger->error('veri_deposu_yok', ['mesaj' => $e->getMessage()]);

            return $this->secure($this->error($view, 503, 'Veri deposuna ulaşılamıyor', $e->getMessage()));
        } catch (Throwable $e) {
            // Ayrıntı günlüğe yazılır (hassas veri içermez); kullanıcıya genel mesaj gösterilir (NFR-10).
            $logger->error('beklenmeyen_hata', ['tur' => $e::class, 'mesaj' => $e->getMessage(), 'yer' => basename($e->getFile()) . ':' . $e->getLine()]);

            return $this->secure($this->error($view, 500, 'Beklenmeyen hata', 'Bir sorun oluştu. Lütfen daha sonra tekrar deneyin.'));
        }
    }

    /** Arka plan isteği: sayfa bölümü, JSON uç noktası veya fetch ile gönderilen form. Yanıtı tarayıcı değil betik okur. */
    private function isBackground(Request $request): bool
    {
        return $this->api || $this->fragment || $request->header('X-Requested-With') === 'fetch';
    }

    /** Oturumu olmayan arka plan isteği giriş sayfasına yönlendirilmez (sayfanın içine giriş formu basılmasın); 401 alır. */
    private function loginRequired(): Response
    {
        return $this->api
            ? Response::json(['basarili' => false, 'hata' => 'Oturum gerekli.'], 401)
            : Response::html('Oturum gerekli.', 401);
    }

    private function error(View $view, int $status, string $title, string $message): Response
    {
        if ($this->api) {
            // JSON uç noktaları hatayı da JSON olarak döndürür
            return Response::json(['basarili' => false, 'hata' => $message], $status);
        }
        if ($this->fragment) {
            // Sayfa bölümü hatayı da bölüm olarak döndürür; tam hata sayfası bölümün içine basılmaz.
            return Response::html($view->render('errors/fragment', ['message' => $message], null), $status);
        }
        $session = $this->container->session();
        $user = $this->container->auth()->user();
        $home = match (true) {
            $user?->role === Role::IlanSahibi => '/panel',
            $user?->role === Role::Yonetici => '/yonetim',
            default => '/',
        };

        return Response::html($view->render('errors/error', [
            'status' => $status,
            'title' => $title,
            'message' => $message,
            'home' => $home,
            'flashes' => $session->takeFlashes(),
        ]), $status);
    }

    /** Temel güvenlik başlıkları. */
    private function secure(Response $response): Response
    {
        $response->headers['X-Content-Type-Options'] = 'nosniff';
        $response->headers['X-Frame-Options'] = 'DENY';
        $response->headers['Referrer-Policy'] = 'same-origin';

        return $response;
    }

    /** Sunumda boş veriyle demo yapılmaz: veri klasörü boşsa örnek veri bir kez yüklenir. */
    private function ensureDemoData(): void
    {
        if ($this->container->needsDemoData()) {
            (new DemoSeeder($this->container))->run();
            $this->container->logger()->info('ornek_veri_yuklendi');
        }
    }
}
