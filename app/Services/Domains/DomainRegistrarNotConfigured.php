<?php

namespace App\Services\Domains;

use RuntimeException;

/**
 * Thrown by the Null ('manual') adapter — and by real adapters missing their
 * credentials — whenever an API operation is asked of a registrar that cannot
 * perform it. Typed so UI actions can catch it and show «ο registrar αυτής της
 * σύνδεσης δεν έχει API» instead of a generic 500. Never swallowed silently:
 * registrar calls sit on money/state paths (docs/domains/README.md §4.2).
 */
class DomainRegistrarNotConfigured extends RuntimeException {}
