# Standalone service configuration.
#
# Copy to config.php and edit. Keep config.php out of version control.

return array(
	// Base URL of the WordPress REST API that holds the catalogue.
	'api_base' => 'https://www.rareanimes.foo/wp-json/wp/v2',

	// Public base URL of this service.
	'base_url' => 'https://api.example.com',

	// Leave empty to run without authentication.
	'api_key' => '',

	// Allowed CORS origin: * or a full origin.
	'cors_origin' => '*',

	// Where parsed series are cached.
	'cache_dir' => __DIR__ . '/cache',

	// Lifetime of a parsed series, in seconds.
	'cache_ttl' => 3600,

	// Lifetime of a resolved link, in seconds. HubCloud signatures last about
	// eight hours, so keep this below that.
	'resolve_ttl' => 1800,

	// Default and maximum page size.
	'per_page'     => 20,
	'max_per_page' => 50,

	// Set to false to serve the host links without resolving them.
	'enable_resolution' => true,

	// Per request timeout for host lookups, in seconds.
	'resolve_timeout' => 25,

	// Optional GoFile account token. GoFile limits how many guest accounts one
	// address may create; open gofile.io in a browser and copy the value of
	// the accountToken cookie.
	'gofile_account' => '',
);
