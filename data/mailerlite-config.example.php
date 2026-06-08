<?php
return [
    // Required for automatic subscriber sync.
    'MAILERLITE_API_KEY' => 'paste-your-mailerlite-api-token-here',

    // Optional: create these groups in MailerLite and paste their numeric IDs here.
    // If these are blank, subscribers will still be created/updated in MailerLite.
    'MAILERLITE_GUIDE_GROUP_ID' => '',
    'MAILERLITE_CONTACT_GROUP_ID' => '',

    // Optional fallback group for all website subscribers.
    'MAILERLITE_GROUP_ID' => '',

    // Optional: set to 'yes' only if importing an older CSV whose contacts gave consent.
    'MAILERLITE_IMPORT_ASSUME_CONSENT' => 'no',
];
