<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\Tenant;

class RegisterController extends Controller
{
    private $currentTenantDatabase;

    public function showRegistrationForm()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        // Validation des données
        $request->validate([
            'company_name' => 'required|string|max:255',
            'subdomain' => 'required|string|max:50|unique:tenants,subdomain|regex:/^[a-z0-9-]+$/',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|max:255',
            'password' => 'required|string|min:8|confirmed',
            'plan' => 'required|in:free,basic,pro,enterprise'
        ], [
            'subdomain.unique' => 'Ce sous-domaine est déjà pris. Veuillez en choisir un autre.',
            'subdomain.regex' => 'Le sous-domaine ne peut contenir que des lettres minuscules, chiffres et tirets.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.'
        ]);

        try {
            DB::beginTransaction();

            // Nettoyer le sous-domaine
            $subdomain = Str::slug($request->subdomain);
            $this->currentTenantDatabase = 'tenant_' . $subdomain;
            $databasePath = database_path($this->currentTenantDatabase . '.sqlite');

            // Vérifier si le fichier de base de données existe déjà
            if (file_exists($databasePath)) {
                return back()->withErrors(['subdomain' => 'Ce sous-domaine est déjà utilisé.'])->withInput();
            }

            // Créer le fichier de base de données SQLite
            touch($databasePath);
            chmod($databasePath, 0666);

            // Créer le tenant
            $tenant = Tenant::create([
                'name' => $request->company_name,
                'subdomain' => $subdomain,
                'database' => $this->currentTenantDatabase,
                'is_active' => true
            ]);

            // Configurer la connexion à la base de données du tenant
            config(['database.connections.tenant.database' => $databasePath]);
            DB::purge('tenant');
            DB::reconnect('tenant');

            // Créer les tables pour le tenant
            $this->createTenantTables();

            // Créer l'utilisateur administrateur
            $userId = DB::connection('tenant')->table('users')->insertGetId([
                'name' => $request->admin_name,
                'email' => $request->admin_email,
                'password' => Hash::make($request->password),
                'tenant_id' => $tenant->id,
                'role' => 'admin',
                'plan' => $request->plan,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Créer les tables de configuration du tenant
            $this->createTenantConfigTables($tenant->id, $request->plan);

            DB::commit();

            // Message de succès avec les informations de connexion
            $successMessage = "🎉 Votre espace entreprise a été créé avec succès !\n\n";
            $successMessage .= "🏢 Entreprise : {$request->company_name}\n";
            $successMessage .= "🌐 URL : {$subdomain}.localhost:8000\n";
            $successMessage .= "👤 Admin : {$request->admin_email}\n";
            $successMessage .= "📦 Plan : " . ucfirst($request->plan) . "\n\n";
            $successMessage .= "Vous pouvez maintenant vous connecter avec votre email et mot de passe.";

            return redirect()->route('login')->with('success', $successMessage);

        } catch (\Exception $e) {
            DB::rollBack();
            
            // Supprimer le fichier de base de données s'il a été créé
            if (file_exists($databasePath)) {
                unlink($databasePath);
            }

            return back()->withErrors(['error' => 'Une erreur est survenue lors de la création de votre espace. Veuillez réessayer.'])->withInput();
        }
    }

    private function createTenantTables()
    {
        // Configurer temporairement la connexion tenant
        $databasePath = database_path($this->currentTenantDatabase . '.sqlite');
        config(['database.connections.tenant.database' => $databasePath]);
        DB::purge('tenant');
        DB::reconnect('tenant');

        // Table des utilisateurs
        DB::connection('tenant')->statement('
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                tenant_id INTEGER,
                role VARCHAR(50) DEFAULT "user",
                plan VARCHAR(50) DEFAULT "free",
                is_active BOOLEAN DEFAULT 1,
                created_at DATETIME,
                updated_at DATETIME
            )
        ');

        // Table des profils utilisateurs
        DB::connection('tenant')->statement('
            CREATE TABLE IF NOT EXISTS user_profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                phone VARCHAR(50),
                position VARCHAR(100),
                department VARCHAR(100),
                avatar VARCHAR(255),
                created_at DATETIME,
                updated_at DATETIME,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ');

        // Table des paramètres du tenant
        DB::connection('tenant')->statement('
            CREATE TABLE IF NOT EXISTS tenant_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER,
                setting_key VARCHAR(100) UNIQUE,
                setting_value TEXT,
                created_at DATETIME,
                updated_at DATETIME
            )
        ');

        // Table des activités
        DB::connection('tenant')->statement('
            CREATE TABLE IF NOT EXISTS activities (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                action VARCHAR(100),
                description TEXT,
                ip_address VARCHAR(45),
                user_agent TEXT,
                created_at DATETIME,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ');
    }

    private function createTenantConfigTables($tenantId, $plan)
    {
        // Insérer les paramètres par défaut du tenant
        $defaultSettings = [
            ['tenant_id' => $tenantId, 'setting_key' => 'company_name', 'setting_value' => 'Mon Entreprise'],
            ['tenant_id' => $tenantId, 'setting_key' => 'plan', 'setting_value' => $plan],
            ['tenant_id' => $tenantId, 'setting_key' => 'max_users', 'setting_value' => $this->getMaxUsers($plan)],
            ['tenant_id' => $tenantId, 'setting_key' => 'storage_limit', 'setting_value' => $this->getStorageLimit($plan)],
            ['tenant_id' => $tenantId, 'setting_key' => 'created_at', 'setting_value' => now()],
        ];

        foreach ($defaultSettings as $setting) {
            DB::connection('tenant')->table('tenant_settings')->insert([
                'tenant_id' => $setting['tenant_id'],
                'setting_key' => $setting['setting_key'],
                'setting_value' => $setting['setting_value'],
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }

    private function getMaxUsers($plan)
    {
        return match($plan) {
            'free' => 5,
            'basic' => 25,
            'pro' => 100,
            'enterprise' => -1, // Illimité
            default => 5
        };
    }

    private function getStorageLimit($plan)
    {
        return match($plan) {
            'free' => '1GB',
            'basic' => '10GB',
            'pro' => '100GB',
            'enterprise' => '1TB',
            default => '1GB'
        };
    }
} 