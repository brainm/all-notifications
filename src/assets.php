<?php
/**
 * Assets helper for Vite build
 * Generates proper asset URLs for CSS and JS files
 */

class AssetsHelper {
    private static $manifest = null;
    private static $manifestPath = null;
    
    /**
     * Get manifest for external access
     */
    public static function getManifest() {
        self::loadManifest();
        return self::$manifest;
    }
    
    /**
     * Load manifest file
     */
    private static function loadManifest() {
        if (self::$manifest !== null) {
            return;
        }
        
        // Try different possible manifest locations
        $possiblePaths = [
            __DIR__ . '/../dist/.vite/manifest.json',
            __DIR__ . '/dist/.vite/manifest.json',
            __DIR__ . '/dist/manifest.json',
            __DIR__ . '/.vite/manifest.json'
        ];
        
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                self::$manifestPath = $path;
                $content = file_get_contents($path);
                self::$manifest = json_decode($content, true);
                return;
            }
        }
        
        // If no manifest found, create empty one for development
        self::$manifest = [];
    }
    
    /**
     * Get asset URL
     */
    public static function asset($path) {
        self::loadManifest();
        
        // In development mode or if manifest is empty, return original path
        if (empty(self::$manifest)) {
            return $path;
        }
        
        // Look for the asset in manifest
        if (isset(self::$manifest[$path])) {
            $asset = self::$manifest[$path];
            return $asset['file'] ?? $path;
        }
        
        // If not found in manifest, return original path
        return $path;
    }
    
    /**
     * Get CSS files for an entry point
     */
    public static function css($entry) {
        self::loadManifest();
        
        if (empty(self::$manifest)) {
            return [];
        }
        
        $asset = self::findEntryByName($entry);
        if (!$asset) {
            return [];
        }
        
        return $asset['css'] ?? [];
    }
    
    /**
     * Get JS file for an entry point
     */
    public static function js($entry) {
        self::loadManifest();
        
        if (empty(self::$manifest)) {
            return $entry;
        }
        
        $asset = self::findEntryByName($entry);
        if (!$asset) {
            return $entry;
        }
        
        return $asset['file'] ?? $entry;
    }
    
    /**
     * Generate CSS link tags
     */
    public static function cssTags($entry) {
        // Combine CSS from the entry and any other CSS chunks in manifest (e.g., vendor CSS)
        self::loadManifest();
        $seen = [];
        $allCss = [];

        foreach (self::css($entry) as $cssFile) {
            if (!isset($seen[$cssFile])) { $seen[$cssFile] = true; $allCss[] = $cssFile; }
        }

        foreach (self::$manifest as $asset) {
            if (isset($asset['css']) && is_array($asset['css'])) {
                foreach ($asset['css'] as $cssFile) {
                    if (!isset($seen[$cssFile])) { $seen[$cssFile] = true; $allCss[] = $cssFile; }
                }
            }
        }

        $tags = '';
        foreach ($allCss as $cssFile) {
            $tags .= '<link rel="stylesheet" href="' . htmlspecialchars($cssFile) . '">' . "\n";
        }
        return $tags;
    }
    
    /**
     * Generate JS script tag
     */
    public static function jsTag($entry) {
        $jsFile = self::js($entry);
        return '<script type="module" src="' . htmlspecialchars($jsFile) . '"></script>' . "\n";
    }
    
    /**
     * Find entry in manifest by name
     */
    private static function findEntryByName($name) {
        self::loadManifest();
        
        if (empty(self::$manifest)) {
            return null;
        }
        
        // Look for entry by name
        foreach (self::$manifest as $key => $asset) {
            if (isset($asset['name']) && $asset['name'] === $name) {
                return $asset;
            }
        }
        
        return null;
    }
    
    /**
     * Check if we're in development mode
     */
    public static function isDev() {
        return false; // Always production mode
    }
}

// Convenience functions
function asset($path) {
    return AssetsHelper::asset($path);
}

function css($entry) {
    return AssetsHelper::cssTags($entry);
}

function js($entry) {
    return AssetsHelper::jsTag($entry);
}

function jsUrl($entry) {
    return AssetsHelper::js($entry);
}

function vite($entry) {
    // Always use manifest (production mode)
    return AssetsHelper::cssTags($entry) . AssetsHelper::jsTag($entry);
}

/**
 * Get Tailwind CSS link tag
 */
function tailwindCss() {
    // Get CSS files from main entry point
    $cssFiles = AssetsHelper::css('main');
    
    if (empty($cssFiles)) {
        // Fallback: try to find any CSS file in manifest
        $manifest = AssetsHelper::getManifest();
        
        foreach ($manifest as $asset) {
            if (isset($asset['css']) && !empty($asset['css'])) {
                $cssFiles = $asset['css'];
                break;
            }
        }
    }
    
    if (empty($cssFiles)) {
        // Last resort fallback
        return '';
    }
    
    $tags = '';
    foreach ($cssFiles as $cssFile) {
        $tags .= '<link rel="stylesheet" href="' . htmlspecialchars($cssFile) . '">' . "\n";
    }
    
    return $tags;
}

/**
 * Favicon, PWA manifest и Apple touch icon.
 */
function webHeadIcons(string $basePath): string {
    $base = rtrim($basePath, '/');
    $h = static fn(string $url): string => htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $tags = '';
    $tags .= '<link rel="icon" type="image/png" href="' . $h($base . '/favicon-96x96.png') . '" sizes="96x96">' . "\n";
    $tags .= '<link rel="icon" type="image/svg+xml" href="' . $h($base . '/favicon.svg') . '">' . "\n";
    $tags .= '<link rel="shortcut icon" href="' . $h($base . '/favicon.ico') . '">' . "\n";
    $tags .= '<link rel="apple-touch-icon" sizes="180x180" href="' . $h($base . '/apple-touch-icon.png') . '">' . "\n";
    $tags .= '<meta name="apple-mobile-web-app-title" content="Уведомления">' . "\n";
    $tags .= '<link rel="manifest" href="' . $h($base . '/site.webmanifest') . '">' . "\n";
    $tags .= '<meta name="theme-color" content="#1a1a2e">' . "\n";

    return $tags;
}
