<?php

namespace MyPinacle\Meta\Messaging;

use Illuminate\Http\Client\Factory as HttpFactory;
use MyPinacle\Meta\GraphApiException;
use MyPinacle\Tracking\Graph;

/**
 * Messenger / Instagram (Page-linked, Facebook Login) messaging over the Graph API, with a Page access token.
 * Graph version from MyPinacle\Tracking\Graph. The token goes to graph.facebook.com only and is never logged.
 * Errors throw GraphApiException (isRateLimit / isTransient / needsReconnect / isOutsideWindow / isRecipientUnavailable).
 */
final class SendClient
{
    public const DEFAULT_FIELDS = ['messages', 'messaging_postbacks', 'messaging_referrals', 'message_echoes', 'message_reactions', 'feed'];

    public function __construct(private readonly HttpFactory $http, private readonly string $pageAccessToken, private readonly int $timeout = 20)
    {
    }

    /**
     * POST /{page_id}/messages. $message = Send API "message" object (text, quick_replies, attachment ...).
     * messaging_type RESPONSE (inside 24h) or MESSAGE_TAG with $tag (e.g. HUMAN_AGENT).
     *
     * @return array{recipient_id?: string, message_id?: string}
     */
    public function send(string $pageId, string $recipientId, array $message, string $messagingType = 'RESPONSE', ?string $tag = null): array
    {
        return $this->post($pageId . '/messages', array_filter([
            'recipient' => ['id' => $recipientId],
            'messaging_type' => $messagingType,
            'tag' => $tag,
            'message' => $message,
        ], fn ($v) => $v !== null));
    }

    public function sendText(string $pageId, string $recipientId, string $text, array $quickReplies = []): array
    {
        $message = ['text' => $text];
        if ($quickReplies) {
            $message['quick_replies'] = array_values($quickReplies);
        }

        return $this->send($pageId, $recipientId, $message);
    }

    /** typing_on / typing_off / mark_seen. */
    public function senderAction(string $pageId, string $recipientId, string $action): array
    {
        return $this->post($pageId . '/messages', ['recipient' => ['id' => $recipientId], 'sender_action' => $action]);
    }

    /** Private reply to a Page / IG comment (one per comment, within 7 days): recipient = {comment_id}. */
    public function privateReply(string $pageId, string $commentId, array $message): array
    {
        return $this->post($pageId . '/messages', ['recipient' => ['comment_id' => $commentId], 'message' => $message]);
    }

    /** Public reply under a comment: Facebook POST /{comment_id}/comments, Instagram POST /{comment_id}/replies. */
    public function replyToComment(string $commentId, string $text, string $platform = 'messenger'): array
    {
        return $this->post($commentId . ($platform === 'instagram' ? '/replies' : '/comments'), ['message' => $text]);
    }

    /** Hide / unhide a comment: Facebook is_hidden, Instagram hide. */
    public function hideComment(string $commentId, bool $hidden = true, string $platform = 'messenger'): bool
    {
        $json = $this->post($commentId, [$platform === 'instagram' ? 'hide' : 'is_hidden' => $hidden]);

        return (bool) ($json['success'] ?? true);
    }

    /** POST /{page_id}/subscribed_apps: subscribe our app to the Page's webhook fields. */
    public function subscribeApp(string $pageId, array $fields = self::DEFAULT_FIELDS): bool
    {
        $json = $this->post($pageId . '/subscribed_apps', ['subscribed_fields' => implode(',', $fields)]);

        return (bool) ($json['success'] ?? false);
    }

    /** @return list<array<string, mixed>> apps subscribed to the Page with their fields */
    public function subscribedApps(string $pageId): array
    {
        return (array) ($this->get($pageId . '/subscribed_apps')['data'] ?? []);
    }

    /** Page name / id / linked Instagram account (health check + IG id discovery). */
    public function page(string $pageId): array
    {
        return $this->get($pageId, ['fields' => 'id,name,instagram_business_account{id,username}']);
    }

    /**
     * Pages the token can manage (System User or user token) with their Page tokens.
     *
     * @return list<array{id: string, name: string, access_token?: string, instagram_business_account?: array}>
     */
    public function pages(int $limit = 100): array
    {
        return array_values((array) ($this->get('me/accounts', ['fields' => 'id,name,access_token,instagram_business_account{id,username}', 'limit' => $limit])['data'] ?? []));
    }

    /** POST /{page_id}/messenger_profile (get_started, greeting, ice_breakers, persistent_menu). */
    public function setMessengerProfile(string $pageId, array $profile, string $platform = 'messenger'): bool
    {
        $json = $this->post($pageId . '/messenger_profile', $profile + ($platform === 'instagram' ? ['platform' => 'instagram'] : []));

        return (bool) ($json['result'] ?? $json['success'] ?? false);
    }

    /** Messenger profile of a contact (name, profile_pic); IG: name, username, profile_pic. */
    public function profile(string $userId, string $fields = 'name,profile_pic'): array
    {
        return $this->get($userId, ['fields' => $fields]);
    }

    private function post(string $path, array $body): array
    {
        $response = $this->http->asJson()->timeout($this->timeout)
            ->post(Graph::url($path) . '?access_token=' . rawurlencode($this->pageAccessToken), $body);

        return GraphApiException::check($response);
    }

    private function get(string $path, array $query = []): array
    {
        $response = $this->http->timeout($this->timeout)->get(Graph::url($path), $query + ['access_token' => $this->pageAccessToken]);

        return GraphApiException::check($response);
    }
}