<?php
declare( strict_types=1 );

namespace WitsValley\Members\Rest;

use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Domain\Notification\EmailTemplateRepository;
use WitsValley\Members\Domain\Notification\Mailer;
use WitsValley\Members\Domain\Notification\TemplateDefaults;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\DomainError;

final class EmailsController extends Controller {

	public function __construct( private EmailTemplateRepository $templates, private Mailer $mailer, private Audit $audit ) {}

	public function routes(): void {
		$cap = 'wvm_manage_settings';
		$this->route( 'GET', '/email-templates', fn() => array_map( array( $this, 'present' ), $this->templates->where( array(), 'template_key ASC' ) ), array( 'any' => array( $cap, 'wvm_edit_members', 'wvm_manage_workshops' ) ) );
		$this->route( 'GET', '/email-templates/(?P<key>[a-z0-9_]+)', fn( $r ) => $this->present( $this->find( (string) $r['key'] ), true ), $cap );
		$this->route( 'PATCH', '/email-templates/(?P<key>[a-z0-9_]+)', fn( $r ) => $this->update( (string) $r['key'], $this->body( $r ) ), $cap );
		$this->route( 'POST', '/email-templates/(?P<key>[a-z0-9_]+)/reset', fn( $r ) => $this->reset( (string) $r['key'] ), $cap );
		$this->route( 'POST', '/email-templates/(?P<key>[a-z0-9_]+)/preview', fn( $r ) => $this->mailer->preview( array_merge( $this->find( (string) $r['key'] ), array_intersect_key( $this->body( $r ), array_flip( array( 'subject', 'preview', 'body_html', 'body_text' ) ) ) ) ), $cap );
		$this->route(
			'POST',
			'/email-templates/(?P<key>[a-z0-9_]+)/test',
			function ( $r ) {
				$this->mailer->test( (string) $r['key'], sanitize_email( (string) ( $this->body( $r )['to'] ?? '' ) ) );
				return array( 'sent' => true );
			},
			$cap
		);
		$this->route( 'GET', '/notifications', fn( $r ) => $this->paged( $this->mailer->list( $this->query( $r ), $this->pagination( $r, 50 ) ) ), array( 'any' => array( $cap, 'wvm_view_members' ) ) );
		$this->route(
			'POST',
			'/notifications/(?P<id>\d+)/retry',
			function ( $r ) {
				$this->mailer->retry( $this->id( $r ) );
				return array( 'queued' => true );
			},
			array( 'any' => array( $cap, 'wvm_edit_members' ) )
		);
	}

	/** @return array<string,mixed> */
	private function find( string $key ): array {
		$t = $this->templates->byKey( $key );
		if ( ! $t ) {
			throw DomainError::notFound( 'template' );
		}
		return $t;
	}

	/**
	 * @param array<string,mixed> $b
	 * @return array<string,mixed>
	 */
	private function update( string $key, array $b ): array {
		$t      = $this->find( $key );
		$before = $this->present( $t, true );
		$row    = array();
		if ( isset( $b['subject'] ) ) {
			$row['subject'] = mb_substr( sanitize_text_field( (string) $b['subject'] ), 0, 200 );
		}
		if ( isset( $b['preview'] ) ) {
			$row['preview'] = mb_substr( sanitize_text_field( (string) $b['preview'] ), 0, 200 );
		}
		if ( isset( $b['body_html'] ) ) {
			$row['body_html'] = wp_kses_post( (string) $b['body_html'] );
		}
		if ( isset( $b['body_text'] ) ) {
			$row['body_text'] = sanitize_textarea_field( (string) $b['body_text'] );
		}
		if ( isset( $b['enabled'] ) ) {
			$row['enabled'] = (bool) $b['enabled'];
		}
		if ( isset( $row['subject'] ) && '' === $row['subject'] ) {
			throw DomainError::validation( array( 'subject' => 'Subject cannot be empty.' ) );
		}
		$this->templates->update( (int) $t['id'], $row );
		$after = $this->present( $this->find( $key ), true );
		$this->audit->log( 'email_template.updated', 'email_template', (int) $t['id'], $before, $after );
		return $after;
	}

	/** @return array<string,mixed> */
	private function reset( string $key ): array {
		$t        = $this->find( $key );
		$defaults = TemplateDefaults::all()[ $key ] ?? null;
		if ( ! $defaults ) {
			throw DomainError::notFound( 'default template' );
		}
		$before = $this->present( $t, true );
		$this->templates->update(
			(int) $t['id'],
			array(
				'subject'   => $defaults['subject'],
				'preview'   => $defaults['preview'],
				'body_html' => $defaults['html'],
				'body_text' => $defaults['text'],
				'enabled'   => true,
			)
		);
		$after = $this->present( $this->find( $key ), true );
		$this->audit->log( 'email_template.reset', 'email_template', (int) $t['id'], $before, $after );
		return $after;
	}

	/**
	 * @param array<string,mixed> $t
	 * @return array<string,mixed>
	 */
	private function present( array $t, bool $full = false ): array {
		$out = array(
			'key'        => (string) $t['template_key'],
			'name'       => (string) $t['name'],
			'subject'    => (string) $t['subject'],
			'preview'    => (string) $t['preview'],
			'enabled'    => (bool) $t['enabled'],
			'customised' => isset( TemplateDefaults::all()[ $t['template_key'] ] ) && TemplateDefaults::all()[ $t['template_key'] ]['html'] !== $t['body_html'],
			'updated_at' => Clock::iso( $t['updated_at'] ),
		);
		if ( $full ) {
			$out['body_html']    = (string) $t['body_html'];
			$out['body_text']    = (string) $t['body_text'];
			$out['placeholders'] = TemplateDefaults::PLACEHOLDERS;
		}
		return $out;
	}
}
