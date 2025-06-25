<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $parts = explode('.', $host);
        $subdomain = $parts[0];

        // Si on est sur le domaine principal, on laisse passer (page d'accueil)
        if (in_array($subdomain, ['www', 'localhost', '127'])) {
            // Mais on redirige les tentatives de connexion vers la page d'accueil
            if ($request->path() === 'login') {
                return redirect('/');
            }
            return $next($request);
        }

        $tenant = Tenant::where('subdomain', $subdomain)->first();

        // Si le locataire n'existe pas, rediriger vers la page d'accueil
        if (!$tenant) {
            return redirect('http://localhost:8000');
        }

        // Si on est sur un sous-domaine valide, rediriger vers la page de connexion
        if ($request->path() === '/') {
            return redirect('/login');
        }

        // Si le locataire est trouvé, configure sa base de données
        if ($tenant) {
            $dbPath = database_path($tenant->database . '.sqlite');

            if (file_exists($dbPath)) {
                Config::set('database.connections.tenant.database', $dbPath);
                DB::purge('tenant');
                DB::reconnect('tenant');
                
                // Optionnellement, définir la connexion par défaut pour la requête
                config(['database.default' => 'tenant']);

                // Rendre le locataire accessible globalement pour cette requête
                app()->singleton(Tenant::class, function () use ($tenant) {
                    return $tenant;
                });
            }
            // Si le locataire existe mais que la BDD n'existe pas, on pourrait vouloir logger une erreur ou rediriger.
            // Pour l'instant, on continue simplement, ce qui mènera probablement à une erreur plus tard si une BDD est nécessaire.
        }
        // Si aucun locataire n'est trouvé, on ne fait rien et on continue.
        // La route sera responsable de gérer le cas où un locataire était attendu.

        return $next($request);
    }
}
