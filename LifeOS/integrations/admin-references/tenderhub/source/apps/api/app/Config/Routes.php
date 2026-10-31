<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->get('/', static fn () => service('response')
    ->setJSON(['data' => ['service' => 'TenderHub API', 'version' => '3.0'], 'meta' => ['now' => date('c')]]));

$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1'], static function ($routes) {

    $routes->get('health', 'HealthController::index');
    $routes->get('email/open/(:num)/(:segment)', 'PublicApi\EmailOpenController::pixel/$1/$2');
    // Email tracking, unsubscribe and provider events (opaque tokens / SNS signatures; no session).
    $routes->get('email/o/(:segment)', 'PublicApi\EmailOpenController::pixelByToken/$1');
    $routes->get('email/c/(:segment)', 'PublicApi\EmailOpenController::click/$1', ['filter' => 'throttle:120']);
    $routes->get('email/unsubscribe/(:segment)', 'PublicApi\EmailOpenController::unsubscribeForm/$1', ['filter' => 'throttle:30']);
    $routes->post('email/unsubscribe/(:segment)', 'PublicApi\EmailOpenController::unsubscribe/$1', ['filter' => 'throttle:30']);
    $routes->post('email/events/ses', 'PublicApi\SesEventController::receive', ['filter' => 'throttle:600']);

    // ----------------------------------------------------------- public
    $routes->group('', ['namespace' => 'App\Controllers\Api\V1\PublicApi', 'filter' => 'throttle:300'], static function ($routes) {
        $routes->get('notices', 'NoticeController::index');
        $routes->get('notices/(:segment)', 'NoticeController::show/$1');
        $routes->get('auctions', 'AuctionController::index');
        $routes->get('auctions/(:segment)', 'AuctionController::show/$1');
        $routes->get('awards', 'MiscController::awards');
        $routes->get('stats/summary', 'MiscController::summary');
        $routes->get('pricing', 'PricingController::index');
        $routes->get('blog', 'BlogController::index');
        $routes->get('blog-categories', 'BlogController::categories');
        $routes->get('blog/(:segment)', 'BlogController::show/$1');
        $routes->get('transparency', 'TransparencyController::index');
        $routes->get('taxonomy/(:segment)', 'MiscController::taxonomy/$1');
        $routes->post('notices/submit-rfp', 'RfpSubmissionController::submit', ['filter' => 'auth-jwt']);
        $routes->post('contact', 'ContactController::submit', ['filter' => 'captcha:contact']);
    });

    // ------------------------------------------------------------- auth
    $routes->group('auth', ['namespace' => 'App\Controllers\Api\V1\Auth', 'filter' => 'throttle:30'], static function ($routes) {
        $routes->get('captcha', 'CaptchaController::config');
        $routes->post('register', 'AuthController::register', ['filter' => 'captcha:register']);
        $routes->post('login', 'AuthController::login', ['filter' => 'captcha:login']);
        $routes->post('google', 'AuthController::google');
        $routes->post('google/link', 'AuthController::googleLink', ['filter' => 'auth-jwt']);
        $routes->post('google/unlink', 'AuthController::googleUnlink', ['filter' => 'auth-jwt']);
        $routes->post('otp/request', 'AuthController::otpRequest', ['filter' => 'captcha:otp']);
        $routes->post('otp/verify', 'AuthController::otpVerify');
        $routes->post('refresh', 'AuthController::refresh');
        $routes->post('logout', 'AuthController::logout');
        $routes->post('forgot-password', 'PasswordResetController::forgotPassword', ['filter' => 'captcha:password-reset']);
        $routes->post('reset-password', 'PasswordResetController::resetPassword');
        $routes->post('verify-email', 'EmailVerificationController::verify');
        $routes->post('resend-verification', 'EmailVerificationController::resend');
        $routes->post('invitation/accept', 'InvitationController::accept');
    });

    // --------------------------------------------------------- payments
    $routes->group('payments', ['namespace' => 'App\Controllers\Api\V1\Payments'], static function ($routes) {
        $routes->get('gateways', 'CheckoutController::gateways', ['filter' => ['auth-jwt', 'tenant', 'org-portal']]);
        $routes->post('checkout', 'CheckoutController::checkout', ['filter' => ['auth-jwt', 'tenant', 'org-portal']]);
        $routes->get('orders/(:segment)', 'CheckoutController::show/$1', ['filter' => ['auth-jwt', 'tenant', 'org-portal']]);
        $routes->post('webhook/payhere', 'CheckoutController::webhookPayHere');
        $routes->post('webhook/stripe', 'CheckoutController::webhookStripe');
        $routes->post('refund', 'RefundController::process', ['filter' => ['auth-jwt', 'tenant', 'group:staff', 'staff-role']]);
    });

    // ----------------------------------------------- member (the bidder)
    // group:bidder runs BEFORE entitlement:* — a wrong-role caller gets 403,
    // not a 402 upsell for a product they cannot buy.
    $routes->group('me', [
        'namespace' => 'App\Controllers\Api\V1\Member',
        'filter'    => ['auth-jwt', 'tenant', 'group:bidder'],
    ], static function ($routes) {
        $routes->get('subscription', 'MemberController::subscription');
        $routes->post('subscription/claim', 'MemberController::claim');
        $routes->get('bids', 'BidController::index');
        $routes->post('bids', 'BidController::create');
        $routes->post('bids/(:num)/stage', 'BidController::move/$1');
        $routes->get('bids/(:num)/tasks', 'BidController::tasks/$1');
        $routes->post('bids/(:num)/tasks', 'BidController::addTask/$1');
        $routes->patch('bids/(:num)/tasks/(:num)', 'BidController::updateTask/$1/$2');
        $routes->get('vault', 'BidController::vault');
        $routes->get('complaints', 'ComplaintController::index');
        $routes->post('complaints', 'ComplaintController::create');
        $routes->post('complaints/(:num)/appeal', 'ComplaintController::appeal/$1');
        $routes->get('tenders/(:num)/clarifications', 'BidController::myClarifications/$1');
        $routes->post('tenders/(:num)/clarifications', 'BidController::askClarification/$1', ['filter' => 'throttle:20']);
    });

    $routes->group('me', [
        'namespace' => 'App\Controllers\Api\V1\Member',
        'filter'    => ['auth-jwt', 'tenant', 'group:bidder', 'entitlement:feed'],
    ], static function ($routes) {
        $routes->get('feed', 'MemberController::feed');
    });

    // Personal tender preferences are shared by bidders and buying companies.
    $routes->group('me', [
        'namespace' => 'App\Controllers\Api\V1\Member',
        'filter' => ['auth-jwt', 'tenant', 'org-portal', 'entitlement:feed'],
    ], static function ($routes) {
        $routes->get('alert-profiles', 'MemberController::profiles');
        $routes->post('alert-profiles', 'MemberController::createProfile');
        $routes->get('alert-profiles/(:num)/preview', 'MemberController::previewProfile/$1');
        $routes->put('alert-profiles/(:num)', 'MemberController::updateProfile/$1');
        $routes->patch('alert-profiles/(:num)', 'MemberController::updateProfile/$1');
        $routes->delete('alert-profiles/(:num)', 'MemberController::deleteProfile/$1');
    });

    $routes->group('me', [
        'namespace' => 'App\Controllers\Api\V1\Member',
        'filter'    => ['auth-jwt', 'tenant', 'group:bidder', 'entitlement:documents'],
    ], static function ($routes) {
        $routes->get('notices/(:segment)', 'MemberController::notice/$1');
        $routes->get('notices/(:num)/documents/(:num)/url', 'MemberController::documentUrl/$1/$2');
        $routes->post('notices/(:num)/answer', 'DocumentAnswerController::answer/$1', ['filter' => 'throttle:20']);
    });

    $routes->group('me', [
        'namespace' => 'App\Controllers\Api\V1\Member',
        'filter'    => ['auth-jwt', 'tenant', 'group:bidder', 'entitlement:esubmission'],
    ], static function ($routes) {
        $routes->post('submissions', 'BidController::lodge');
        $routes->get('submissions/(:num)/receipt', 'BidController::receipt/$1');
        $routes->post('tenders/(:num)/buy-documents', '\App\Controllers\Api\V1\Authority\SaleController::buyDocuments/$1');
    });

    // ----------------------------------- authority (the buy-side workspace)
    $routes->group('authority', [
        'namespace' => 'App\Controllers\Api\V1\Authority',
        'filter'    => ['auth-jwt', 'tenant', 'group:company', 'org-portal', 'entitlement:publish'],
    ], static function ($routes) {
        $routes->get('tenders', 'TenderController::index');
        $routes->post('tenders', 'TenderController::create');
        $routes->get('tenders/(:num)', 'TenderController::show/$1');
        $routes->post('tenders/(:num)/submit-for-approval', 'TenderController::submitForApproval/$1');
        $routes->post('tenders/(:num)/approve', 'TenderController::approve/$1');
        $routes->post('tenders/(:num)/publish', 'TenderController::publish/$1');
        $routes->put('tenders/(:num)', 'TenderController::update/$1');
        $routes->post('tenders/(:num)/cancel', 'TenderController::cancel/$1');

        $routes->get('tenders/(:num)/documents', 'SaleController::documents/$1');
        $routes->post('tenders/(:num)/documents', 'SaleController::upload/$1');
        $routes->get('tenders/(:num)/documents/(:num)/url', 'SaleController::documentUrl/$1/$2');
        $routes->delete('tenders/(:num)/documents/(:num)', 'SaleController::deleteDocument/$1/$2');
        $routes->get('tenders/(:num)/documents/(:num)/versions', 'SaleController::versions/$1/$2');
        $routes->post('tenders/(:num)/documents/(:num)/version', 'SaleController::uploadVersion/$1/$2');
        $routes->get('tenders/(:num)/purchasers', 'SaleController::purchasers/$1');
        $routes->post('tenders/(:num)/purchasers', 'SaleController::registerPurchase/$1');
        $routes->get('tenders/(:num)/clarifications', 'SaleController::clarifications/$1');
        $routes->post('tenders/(:num)/clarifications/(:num)/answer', 'SaleController::answer/$1/$2');
        $routes->get('tenders/(:num)/addenda', 'SaleController::addenda/$1');
        $routes->post('tenders/(:num)/addenda', 'SaleController::issueAddendum/$1');

        $routes->get('tenders/(:num)/submissions', 'OpeningController::submissions/$1');
        $routes->post('tenders/(:num)/opening/start', 'OpeningController::start/$1');
        $routes->post('tenders/(:num)/opening/countersign', 'OpeningController::countersign/$1');

        $routes->get('tenders/(:num)/evaluation', 'EvaluationController::sheet/$1');
        $routes->post('tenders/(:num)/evaluation/coi', 'EvaluationController::declare/$1');
        $routes->post('tenders/(:num)/evaluation/criteria', 'EvaluationController::criteria/$1');
        $routes->post('tenders/(:num)/evaluation/scores', 'EvaluationController::score/$1');

        $routes->get('tenders/(:num)/award', 'AwardController::show/$1');
        $routes->post('tenders/(:num)/award', 'AwardController::create/$1');
        $routes->post('tenders/(:num)/rating', 'AwardController::rate/$1');
        $routes->get('tenders/(:num)/evidence', 'AwardController::evidence/$1');
        $routes->get('tenders/(:num)/ledger', 'AuditController::ledger/$1');
        $routes->get('tenders/(:num)/complaints', 'ComplaintController::forTender/$1');
        $routes->post('complaints/(:num)/transition', 'ComplaintController::transition/$1');

        // Procurement planning
        $routes->get('plans', 'PlanController::index');
        $routes->post('plans', 'PlanController::create');
        $routes->post('plans/(:num)/submit', 'PlanController::submit/$1');
        $routes->post('plans/(:num)/approve', 'PlanController::approve/$1');
        $routes->post('plans/(:num)/revise', 'PlanController::revise/$1');
        $routes->post('plans/(:num)/link', 'PlanController::linkTender/$1');

        // Contract management (post-award lifecycle)
        $routes->get('contracts', 'ContractController::index');
        $routes->post('contracts', 'ContractController::create');
        $routes->get('contracts/(:num)', 'ContractController::show/$1');
        $routes->post('contracts/(:num)/activate', 'ContractController::activate/$1');
        $routes->post('contracts/(:num)/transition', 'ContractController::transition/$1');
        $routes->post('contracts/(:num)/milestones', 'ContractController::addMilestone/$1');
        $routes->post('contracts/(:num)/milestones/(:num)/meet', 'ContractController::meetMilestone/$1/$2');
        $routes->post('contracts/(:num)/variations', 'ContractController::addVariation/$1');
        $routes->post('contracts/(:num)/invoices', 'ContractController::addInvoice/$1');

        // Digital signing (app-level attestation)
        $routes->get('tenders/(:num)/signatures', 'SigningController::forTender/$1');
        $routes->post('tenders/(:num)/sign', 'SigningController::sign/$1');

        $routes->get('suppliers', 'TeamController::suppliers');
        $routes->post('rules/evaluate', 'ComplianceController::evaluate');
        $routes->get('calendar', 'CalendarController::index');
        $routes->get('analytics', 'AnalyticsController::index');
        $routes->post('tenders/(:num)/tco', 'TcoController::assess/$1');
        $routes->get('tenders/(:num)/tco', 'TcoController::show/$1');
        $routes->get('team', 'TeamController::index');
        $routes->post('team/invitations', 'TeamController::invite');
        $routes->put('team/(:num)/role', 'TeamController::changeRole/$1');
        $routes->get('profile', 'TeamController::profile');
        $routes->put('profile', 'TeamController::profile');

        $routes->get('auctions', 'AuctionWorkspaceController::index');
        $routes->post('auctions', 'AuctionWorkspaceController::create');
        $routes->post('auctions/(:num)/publish', 'AuctionWorkspaceController::publish/$1');
        $routes->post('auctions/(:num)/result', 'AuctionWorkspaceController::result/$1');
    });

    // ------------------------------------------------------------ admin
    // ------------------------------------------- account (any authenticated org)
    $routes->group('account', [
        'namespace' => 'App\Controllers\Api\V1\Account',
        'filter'    => ['auth-jwt', 'tenant', 'org-portal'],
    ], static function ($routes) {
        $routes->get('profile', 'ProfileController::show');
        // Whether the Organisation Portal is open (see OrganisationPortal).
        $routes->get('portal-status', 'PortalStatusController::show');
        // The signed-in user's own WorkSuite connection (see WorkSuiteController).
        $routes->get('integrations/worksuite', 'WorkSuiteController::show');
        $routes->post('integrations/worksuite', 'WorkSuiteController::connect', ['filter' => 'throttle:10']);
        $routes->post('integrations/worksuite/test', 'WorkSuiteController::test', ['filter' => 'throttle:20']);
        $routes->delete('integrations/worksuite', 'WorkSuiteController::disconnect');
        $routes->get('integrations/worksuite/links', 'WorkSuiteController::links');
        $routes->get('integrations/worksuite/tenders/(:num)', 'WorkSuiteController::tenderStatus/$1');
        $routes->post('integrations/worksuite/tenders/(:num)', 'WorkSuiteController::syncTender/$1', ['filter' => 'throttle:30']);
        $routes->get('subscription', '\App\Controllers\Api\V1\Member\MemberController::subscription');
        $routes->post('subscription/claim', '\App\Controllers\Api\V1\Member\MemberController::claim');
        $routes->get('api-keys', 'ApiKeyController::index');
        $routes->post('api-keys', 'ApiKeyController::create', ['filter'=>'throttle:10']);
        $routes->post('api-keys/(:num)/revoke', 'ApiKeyController::revoke/$1');
        $routes->put('profile', 'ProfileController::update');
        $routes->get('categories', 'ProfileController::categories');
        $routes->get('kyc', 'KycController::status');
        $routes->get('kyc/requirements', 'KycController::requirements');
        $routes->post('kyc', 'KycController::submit');
        $routes->get('notifications', 'NotificationController::index');
        $routes->post('notifications/(:num)/read', 'NotificationController::read/$1');
        $routes->post('notifications/read-all', 'NotificationController::readAll');
        $routes->get('notifications/unread-count', 'NotificationController::unreadCount');
        $routes->post('privacy/requests', 'PrivacyController::request');
        $routes->get('privacy/export', 'PrivacyController::export');
        $routes->post('password', 'PrivacyController::changePassword', ['filter' => 'throttle:10']);
    });

    $routes->group('admin', [
        'namespace' => 'App\Controllers\Api\V1\Admin',
        'filter'    => ['auth-jwt', 'tenant', 'group:staff', 'staff-role', 'entitlement:admin'],
    ], static function ($routes) {
        $routes->get('reports/health', 'ReportController::health');
        $routes->get('session', 'SessionController::show');
        $routes->get('reports/coverage', 'ReportController::coverage');
        $routes->get('infrastructure/status', 'InfrastructureController::status');
        $routes->get('infrastructure/maintenance', 'InfrastructureController::maintenance');
        $routes->post('infrastructure/maintenance/safe-cleanup', 'InfrastructureController::queueSafeCleanup', ['filter' => 'throttle:3']);
        $routes->get('reports/automation', 'ReportController::automation');
        $routes->get('notices', 'ReportController::queue');
        $routes->post('notices/(:num)/publish', 'ReportController::publishNotice/$1');
        $routes->post('notices/(:num)/merge', 'ReportController::merge/$1');
        $routes->get('ingest/sources', 'IngestionController::sources');
        $routes->get('ingest/quality', 'QualityController::index');
        $routes->get('ingest/schedule', 'IngestionController::schedule');
        $routes->post('ingest/schedule', 'IngestionController::updateSchedule');
        $routes->get('ingest/live-run', 'IngestionController::liveRunStatus');
        $routes->get('ingest/telemetry', 'IngestionController::telemetry');
        $routes->post('ingest/live-run', 'IngestionController::queueLiveRun', ['filter' => 'throttle:3']);
        $routes->post('ingest/rest-api-key', 'IngestionController::generateRestApiKey');
        $routes->post('ingest/rest-sources', 'IngestionController::createRestSource');
        $routes->post('ingest/website-sources', 'IngestionController::createWebsiteSource');
        // Import control. The 501 stub previously here is now implemented in
        // IngestionController; the console run is deliberately bounded and the
        // full backfill lives in `php spark tenders:import`.
        $routes->post('ingest/sources/(:num)/run', 'IngestionController::run/$1');
        $routes->post('ingest/sources/(:num)/pause', 'IngestionController::pause/$1');
        $routes->post('ingest/sources/(:num)/resume', 'IngestionController::resume/$1');
        $routes->post('ingest/sources/(:num)/toggle', 'IngestionController::toggle/$1');
        $routes->post('ingest/sources/(:num)/credentials', 'IngestionController::credentials/$1');
        $routes->post('ingest/sources/(:num)/collect', 'IngestionController::collect/$1');
        $routes->get('ingest/runs', 'IngestionController::runs');
        $routes->get('organisations', 'ReportController::organisations');
        $routes->post('organisations/(:num)/verify', 'ReportController::verifyOrg/$1');
        $routes->get('kyc', 'KycController::pending');
        $routes->get('kyc/documents/(:num)', 'KycController::document/$1');
        $routes->get('kyc/(:num)', 'KycController::show/$1');
        $routes->post('kyc/(:num)/start-review', 'KycController::startReview/$1');
        $routes->post('kyc/(:num)/review', 'KycController::review/$1');
        $routes->post('organisations/(:num)/suspend', 'KycController::suspend/$1');
        $routes->get('risk-signals', 'RiskController::signals');
        $routes->get('security/events', 'SecurityController::events');
        $routes->get('legal-holds', 'RetentionController::holds');
        $routes->post('legal-holds', 'RetentionController::place');
        $routes->post('legal-holds/(:num)/release', 'RetentionController::release/$1');
        $routes->get('payments', 'PaymentController::index');
        $routes->get('payments/gateways', 'PaymentGatewayController::index');
        $routes->post('payments/gateways/(:segment)', 'PaymentGatewayController::configure/$1', ['filter' => 'throttle:10']);
        $routes->post('payments/gateways/(:segment)/disable', 'PaymentGatewayController::disable/$1');
        $routes->get('payments/(:num)/receipt', 'PaymentController::receipt/$1');
        $routes->get('api-keys', 'ApiKeyController::index');
        $routes->post('api-keys/(:num)/review', 'ApiKeyController::review/$1');
        $routes->post('api-keys', 'ApiKeyController::create', ['filter' => 'throttle:10']);
        $routes->get('api-keys/documentation', 'ApiKeyController::documentation');
        $routes->post('payments/(:num)/confirm', 'PaymentController::confirm/$1');
        $routes->post('payments/(:num)/reject', 'PaymentController::reject/$1');
        $routes->get('users', 'UserController::index');
        $routes->get('users/export.xlsx', 'UserController::exportXlsx');
        $routes->get('users/export.csv', 'UserController::exportCsv');
        $routes->get('users/export.pdf', 'UserController::exportPdf');
        $routes->post('users/(:num)/verify', 'UserController::verify/$1');
        $routes->post('users', 'UserController::create');
        $routes->get('users/(:num)', 'UserController::show/$1');
        $routes->post('users/(:num)/suspend', 'UserController::suspend/$1');
        $routes->post('users/(:num)/reactivate', 'UserController::reactivate/$1');
        $routes->post('users/(:num)/convert-to-bidder', 'UserController::convertToBidder/$1');
        $routes->get('trials', 'TrialController::index');
        $routes->post('users/(:num)/delete', 'UserController::delete/$1');
        $routes->delete('users/(:num)', 'UserController::delete/$1');

        // Email Gateway (SMTP transport control)
        $routes->get('email/status', 'EmailController::status');
        $routes->get('email/analytics', 'EmailController::analytics');
        $routes->get('email/analytics/(:num)', 'EmailController::dispatch/$1');
        $routes->post('email/toggle', 'EmailController::toggle');
        $routes->post('email/test', 'EmailController::test');
        $routes->post('email/retry-failed', 'EmailController::retryFailed');
        $routes->post('email/settings', 'EmailController::updateSettings');
        $routes->get('publishing', 'PublishingController::status');
        // Social marketing: the day's collected tenders (posting runs in the marketing engine)
        $routes->get('social/today-tenders', 'SocialController::todayTenders');
        $routes->get('integrations/worksuite', 'WorkSuiteAdminController::health');
        $routes->post('integrations/worksuite', 'WorkSuiteAdminController::update');
        // Organisation Portal switch (owners and admins; audited).
        $routes->get('portals/organisation', 'OrganisationPortalController::show');
        $routes->post('portals/organisation', 'OrganisationPortalController::update', ['filter' => 'throttle:10']);
        $routes->post('publishing', 'PublishingController::update');
        $routes->post('publishing/release-held', 'PublishingController::releaseHeld');
        $routes->get('email/trace', 'EmailController::trace');
        $routes->get('email/suppressions', 'EmailController::suppressions');
        $routes->get('support', 'SupportController::index');
        $routes->post('support/messages/(:num)', 'SupportController::updateMessage/$1');
        $routes->post('support/mailboxes', 'SupportController::createMailbox');
        $routes->get('blog', 'BlogController::index');
        $routes->get('blog/(:num)', 'BlogController::show/$1');
        $routes->post('blog', 'BlogController::create');
        $routes->put('blog/(:num)', 'BlogController::update/$1');
        $routes->delete('blog/(:num)', 'BlogController::delete/$1');

        // SEO & Web Optimization Engine (UWOE)
        $routes->get('seo/status', 'SeoController::status');
        $routes->post('seo/audit', 'SeoController::audit');
        $routes->post('seo/platforms/sync', 'SeoController::syncPlatforms', ['filter' => 'throttle:5']);
        $routes->get('seo/recommendations', 'SeoController::recommendations');
        $routes->get('seo/dashboard', 'SeoController::dashboard');
        $routes->get('seo/report', 'SeoController::report');
        $routes->get('seo/indexnow', 'SeoController::indexNow');
        $routes->post('seo/indexnow', 'SeoController::updateIndexNow');
        $routes->get('seo/backlinks', 'BacklinkController::index');
        $routes->post('seo/backlinks', 'BacklinkController::create', ['filter' => 'throttle:30']);
        $routes->post('seo/backlinks/import', 'BacklinkController::import', ['filter' => 'throttle:5']);
        $routes->post('seo/backlinks/provider-sync', 'BacklinkController::syncProvider', ['filter' => 'throttle:3']);
        $routes->post('seo/backlinks/(:num)/verify', 'BacklinkController::verify/$1', ['filter' => 'throttle:20']);
        $routes->get('seo/backlinks/export.csv', 'BacklinkController::export');
        $routes->post('seo/backlinks/outreach', 'BacklinkController::saveOutreach');
        $routes->post('seo/backlinks/outreach/(:num)', 'BacklinkController::saveOutreach/$1');
        $routes->get('acquisition', 'AcquisitionController::index');
    });

    // ----------------------------------------------------------- crawler ingestion
    // Authenticated via X-Ingest-Key in controller
    $routes->post('admin/ingest/push', 'Admin\IngestWebhookController::push');
    $routes->post('ingest/push', 'Admin\IngestWebhookController::push');
    $routes->get('internal/social/today-tenders', 'Admin\SocialController::automationTenders');

    // TenderHub outward REST feed for systems such as Worksuite cron imports.
    $routes->get('tenders/feed', 'Partner\PartnerController::notices', ['filter' => 'api-key']);

    // ---------------------------------------------------------- partner
    $routes->group('partner', [
        'namespace' => 'App\Controllers\Api\V1\Partner',
        'filter'    => 'api-key',
    ], static function ($routes) {
        $routes->get('notices', 'PartnerController::notices');
        $routes->post('webhooks', 'PartnerController::registerWebhook');
        $routes->get('webhooks', 'PartnerController::listWebhooks');
        $routes->delete('webhooks/(:num)', 'PartnerController::deleteWebhook/$1');
    });

    // The signature IS the authorisation — deliberately no auth filter here.
    $routes->get('files/documents/(:num)', '\App\Controllers\Api\V1\Files\FileController::document/$1');
    $routes->get('files/notice-image/(:num)', '\App\Controllers\Api\V1\Files\FileController::noticeImage/$1');
});
