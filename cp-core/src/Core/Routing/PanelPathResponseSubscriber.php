<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Security\Http\CspNonceProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The outbound half of PanelPaths for what the router cannot see: hand-written
 * redirects, and scripts (core or module) that fetch a literal "/admin/..." path.
 */
final class PanelPathResponseSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CspNonceProvider $nonces,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -900]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $map = PanelPaths::map();
        if ($map === [] || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $base = $request->getBaseUrl();

        $location = (string) $response->headers->get('Location', '');
        if ($location !== '') {
            $origin = $request->getSchemeAndHttpHost();
            $prefix = str_starts_with($location, $origin.'/') ? $origin : '';
            $response->headers->set('Location', $prefix.PanelPaths::toPublic(substr($location, \strlen($prefix)), $base, $map));
        }

        // Only a signed-in visitor is told the panel addresses; the shim must never reveal them to the public.
        if (!$this->isPanelRequest($request->getPathInfo())
            || !str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')
        ) {
            return;
        }
        $html = $response->getContent();
        if ($html === false || ($head = stripos($html, '<head>')) === false) {
            return;
        }

        unset($map['login']);
        if ($map === []) {
            return;
        }

        $script = \sprintf(
            '<script nonce="%s">(()=>{const b=%s,m=%s,t=u=>{if(typeof u!=="string")return u;for(const[i,p]of Object.entries(m)){const f=b+"/"+i;if(u===f||(u.startsWith(f)&&"/?#".includes(u[f.length])))return b+"/"+p+u.slice(f.length)}return u},F=window.fetch;window.fetch=(u,o)=>F(t(u),o);const O=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(a,u,...r){return O.call(this,a,t(u),...r)}})();</script>',
            htmlspecialchars($this->nonces->nonceFor($request), \ENT_QUOTES),
            json_encode($base, \JSON_THROW_ON_ERROR | \JSON_HEX_TAG),
            json_encode($map, \JSON_THROW_ON_ERROR | \JSON_HEX_TAG),
        );
        $response->setContent(substr_replace($html, $script, $head + 6, 0));
    }

    private function isPanelRequest(string $internalPath): bool
    {
        return preg_match('~^/(admin|aacp)(/|$)~', $internalPath) === 1
            && !str_starts_with($internalPath, '/aacp/recovery');
    }
}
