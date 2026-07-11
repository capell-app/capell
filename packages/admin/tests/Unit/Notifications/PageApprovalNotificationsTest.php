<?php

declare(strict_types=1);

use Capell\Admin\Notifications\PageApprovedNotification;
use Capell\Admin\Notifications\PageRejectedNotification;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Foundation\Auth\User;

it('builds approved page mail and array payloads', function (): void {
    config(['app.url' => 'https://capell.test']);

    $page = new Page(['name' => 'About us']);
    $page->id = 123;
    $page->setRelation('site', new Site(['name' => 'Primary site']));

    $approver = approvalNotificationUser(id: 9, name: 'Reviewer', email: 'reviewer@example.com');
    $notifiable = approvalNotificationUser(id: 10, name: 'Author', email: 'author@example.com');

    $notification = new PageApprovedNotification($page, $approver);
    $mail = $notification->toMail($notifiable);

    expect($notification->via($notifiable))->toBe(['mail'])
        ->and($mail->subject)->toBe(__('capell-admin::notification.approved_subject', [
            'page' => 'About us',
            'site' => 'Primary site',
        ]))
        ->and($mail->actionText)->toBe(__('capell-admin::notification.approved_cta'))
        ->and($mail->actionUrl)->toBeString()
        ->and($mail->actionUrl)->not->toBe('')
        ->and($notification->toArray($notifiable))->toBe([
            'page_id' => 123,
            'page_name' => 'About us',
            'approver' => 9,
        ]);
});

it('builds rejected page mail with reviewer notes and array payloads', function (): void {
    config(['app.url' => 'https://capell.test']);

    $page = new Page(['name' => 'Pricing']);
    $page->id = 456;

    $rejector = approvalNotificationUser(id: 11, name: null, email: 'rejector@example.com');
    $notifiable = approvalNotificationUser(id: 12, name: null, email: 'author@example.com');

    $notification = new PageRejectedNotification($page, $rejector, 'Tighten the opening paragraph.');
    $mail = $notification->toMail($notifiable);

    expect($notification->via($notifiable))->toBe(['mail'])
        ->and($mail->subject)->toBe(__('capell-admin::notification.rejected_subject', [
            'page' => 'Pricing',
            'site' => 'Capell',
        ]))
        ->and(collect($mail->introLines)->implode("\n"))->toContain('Tighten the opening paragraph.')
        ->and($mail->actionText)->toBe(__('capell-admin::notification.rejected_cta'))
        ->and($notification->toArray($notifiable))->toBe([
            'page_id' => 456,
            'page_name' => 'Pricing',
            'rejector' => 11,
            'notes' => 'Tighten the opening paragraph.',
        ]);
});

function approvalNotificationUser(int $id, ?string $name, string $email): User
{
    $user = new User;
    $user->id = $id;
    $user->setAttribute('name', $name);
    $user->setAttribute('email', $email);

    return $user;
}
