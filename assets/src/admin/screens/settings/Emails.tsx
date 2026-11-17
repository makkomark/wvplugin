import { useEffect, useRef, useState } from 'react';
import type { Route } from '../../router';
import { replaceQuery } from '../../router';
import { useApi } from '../../hooks/useApi';
import { api, fieldError } from '../../lib/api';
import { config } from '../../lib/config';
import type { EmailTemplateRow } from '../../lib/types';
import { relativeTime } from '../../../shared/format';
import { Btn, Chip, Mono, Muted, EmptyState, Loading, ErrorState, Tabs, cx } from '../../components/ui';
import { Icon } from '../../components/Icon';
import { DataTable } from '../../components/DataTable';
import { SearchInput } from '../../components/FilterBar';
import { EntityDrawer, FormModal, ConfirmModal } from '../../components/overlays';
import { TextField, SelectField, Toggle, FieldGrid } from '../../components/fields';
import { useToast } from '../../components/Toasts';
import { useSettingsGroup, SettingsFrame, Section } from './shared';

interface EmailValues extends Record< string, unknown > {
	from_name: string;
	from_email: string;
	driver: 'wp_mail' | 'postmark' | 'sendgrid';
	provider_key: string;
	provider_key_set?: boolean;
	bcc_admin_on: string[];
	admin_alerts: string;
	brand_color: string;
	logo_url: string;
}

interface WpMediaFrame {
	on: ( ev: string, cb: () => void ) => void;
	open: () => void;
	state: () => { get: ( k: string ) => { first: () => { toJSON: () => { url: string } } } };
}

export function EmailsSettings( { route }: { route: Route } ) {
	const s = useSettingsGroup< EmailValues >( 'emails' );
	const templates = useApi< EmailTemplateRow[] >( 'email-templates' );
	const [ open, setOpen ] = useState< string | null >( route.query.template || null );
	const [ tab, setTab ] = useState( route.query.template ? 'templates' : route.query.view || 'templates' );
	const [ q, setQ ] = useState( '' );
	const toast = useToast();
	const v = s.values;
	useEffect( () => {
		if ( route.query.template ) {
			setOpen( route.query.template );
			setTab( 'templates' );
		}
	}, [ route.query.template ] );

	const pickLogo = () => {
		const wp = ( window as unknown as { wp?: { media?: ( o: Record< string, unknown > ) => WpMediaFrame } } ).wp;
		if ( ! wp?.media ) {
			toast.info( 'The media library isn’t available here — paste a URL instead.' );
			return;
		}
		const frame = wp.media( { title: 'Choose email logo', button: { text: 'Use this image' }, multiple: false, library: { type: 'image' } } );
		frame.on( 'select', () => {
			const att = frame.state().get( 'selection' ).first().toJSON();
			s.set( { logo_url: att.url } );
		} );
		frame.open();
	};

	const rows = ( templates.data || [] ).filter( ( t ) => ! q || ( t.name + t.key + t.subject ).toLowerCase().includes( q.toLowerCase() ) );

	return (
		<SettingsFrame title="Emails" description="Transactional email sender, delivery provider and the templates members receive." state={ tab === 'sending' ? s : undefined }>
			<Tabs
				variant="segmented"
				value={ tab }
				onChange={ ( t ) => {
					setTab( t );
					replaceQuery( { view: t } );
				} }
				tabs={ [
					{ key: 'templates', label: 'Templates', count: templates.data?.length },
					{ key: 'sending', label: 'Sending & branding' },
				] }
			/>
			{ tab === 'sending' && v && (
				<>
					<Section title="Sender">
						<FieldGrid>
							<TextField label="From name" value={ v.from_name } onChange={ ( from_name ) => s.set( { from_name } ) } />
							<TextField label="From email" type="email" value={ v.from_email } onChange={ ( from_email ) => s.set( { from_email } ) } error={ fieldError( s.error, 'from_email' ) } />
						</FieldGrid>
						<TextField label="Admin alerts go to" value={ v.admin_alerts } onChange={ ( admin_alerts ) => s.set( { admin_alerts } ) } help="Review-queue items, failed jobs and health alerts." />
					</Section>
					<Section title="Delivery" description="wp_mail uses whatever SMTP plugin you have installed.">
						<FieldGrid>
							<SelectField
								label="Driver"
								value={ v.driver }
								onChange={ ( driver ) => s.set( { driver: driver as EmailValues[ 'driver' ] } ) }
								options={ [
									{ value: 'wp_mail', label: 'WordPress (wp_mail / SMTP plugin)' },
									{ value: 'postmark', label: 'Postmark API' },
									{ value: 'sendgrid', label: 'SendGrid API' },
								] }
							/>
							<TextField
								label="Provider API key"
								type="password"
								value={ v.provider_key }
								onChange={ ( provider_key ) => s.set( { provider_key } ) }
								placeholder={ v.provider_key_set ? '' : 'Not set' }
								help={ v.provider_key_set ? 'Stored encrypted. Leave as is to keep the current key.' : v.driver === 'wp_mail' ? 'Not needed for wp_mail.' : 'Required for API drivers.' }
							/>
						</FieldGrid>
					</Section>
					<Section title="Branding">
						<FieldGrid>
							<div className="wvm-a-field">
								<span className="wvm-a-label">Brand colours &amp; fonts</span>
								<p className="wvm-a-help">
									Emails use the colours set in <a href="#/settings/branding">Settings › Branding</a>.
								</p>
							</div>
							<div className="wvm-a-field">
								<span className="wvm-a-label">Logo</span>
								<div className="wvm-a-logofield">
									{ v.logo_url ? <img src={ v.logo_url } alt="" /> : <span className="wvm-a-logofield__empty"><Icon name="sparkles" size={ 16 } /></span> }
									<input className="wvm-a-input" value={ v.logo_url } onChange={ ( e ) => s.set( { logo_url: e.target.value } ) } placeholder="https://…/logo.png" />
									<Btn size="compact" onClick={ pickLogo }>
										Choose
									</Btn>
								</div>
							</div>
						</FieldGrid>
					</Section>
					<Section title="BCC admin on" description="Send a blind copy of these emails to the admin alerts address.">
						<div className="wvm-a-checkpills is-wrap">
							{ ( templates.data || [] ).map( ( t ) => {
								const on = v.bcc_admin_on.includes( t.key );
								return (
									<button key={ t.key } type="button" title={ t.key } className={ cx( on && 'is-on' ) } onClick={ () => s.set( { bcc_admin_on: on ? v.bcc_admin_on.filter( ( x ) => x !== t.key ) : [ ...v.bcc_admin_on, t.key ] } ) }>
										{ on && <Icon name="check" size={ 12 } strokeWidth={ 2.6 } /> }
										{ t.name }
									</button>
								);
							} ) }
						</div>
					</Section>
				</>
			) }
			{ tab === 'sending' && ! v && <Loading /> }
			{ tab === 'templates' && (
				<div className="wvm-a-card is-flush is-inset">
					<div className="wvm-a-filterbar">
						<div className="wvm-a-filterbar__main">
							<SearchInput value={ q } onChange={ setQ } delay={ 0 } placeholder="Search templates" />
						</div>
					</div>
					<DataTable
						columns={ [
							{ key: 'name', header: 'Template', render: ( t ) => <div className="wvm-a-person is-plain"><div><strong>{ t.name }</strong><span><Mono>{ t.key }</Mono></span></div></div> },
							{ key: 'subject', header: 'Subject', render: ( t ) => <span className="wvm-a-ellipsis">{ t.subject }</span> },
							{ key: 'state', header: '', render: ( t ) => ( <span className="wvm-a-inline">{ t.customised && <Chip tone="primary">Customised</Chip> }{ ! t.enabled && <Chip tone="neutral">Disabled</Chip> }</span> ) },
							{ key: 'updated', header: 'Updated', render: ( t ) => <Muted>{ relativeTime( t.updated_at ) }</Muted> },
						] }
						rows={ templates.data ? rows : undefined }
						rowKey={ ( t ) => t.key }
						loading={ templates.loading }
						error={ templates.error }
						onRetry={ templates.reload }
						onRowClick={ ( t ) => {
							setOpen( t.key );
							replaceQuery( { template: t.key } );
						} }
						empty={ <EmptyState icon="mail" title="No templates" /> }
					/>
				</div>
			) }
			<TemplateEditor
				tkey={ open }
				onClose={ () => {
					setOpen( null );
					replaceQuery( { view: 'templates' } );
				} }
				onSaved={ templates.reload }
			/>
		</SettingsFrame>
	);
}

function TemplateEditor( { tkey, onClose, onSaved }: { tkey: string | null; onClose: () => void; onSaved: () => void } ) {
	const toast = useToast();
	const res = useApi< EmailTemplateRow >( tkey ? `email-templates/${ tkey }` : null );
	const t = res.data && res.data.key === tkey ? res.data : undefined;
	const [ f, setF ] = useState( { subject: '', preview: '', body_html: '', body_text: '', enabled: true } );
	const [ mode, setMode ] = useState< 'html' | 'text' >( 'html' );
	const [ preview, setPreview ] = useState< { subject: string; html: string } | null >( null );
	const [ previewing, setPreviewing ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ err, setErr ] = useState< unknown >( null );
	const [ testOpen, setTestOpen ] = useState( false );
	const [ resetOpen, setResetOpen ] = useState( false );
	const [ to, setTo ] = useState( config.user.email );
	const [ device, setDevice ] = useState< 'desktop' | 'mobile' >( 'desktop' );
	const htmlRef = useRef< HTMLTextAreaElement >( null );
	const textRef = useRef< HTMLTextAreaElement >( null );
	const lastFocus = useRef< 'subject' | 'preview' | 'html' | 'text' >( 'html' );
	const subjectRef = useRef< HTMLInputElement >( null );

	useEffect( () => {
		if ( t ) {
			setF( { subject: t.subject, preview: t.preview, body_html: t.body_html || '', body_text: t.body_text || '', enabled: t.enabled } );
			setErr( null );
		}
	}, [ t?.key, t?.updated_at ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Live preview (debounced) via POST /email-templates/{key}/preview.
	useEffect( () => {
		if ( ! tkey || ! t ) {
			return;
		}
		setPreviewing( true );
		const h = setTimeout( () => {
			api.post< { subject: string; html: string } >( `email-templates/${ tkey }/preview`, { subject: f.subject, preview: f.preview, body_html: f.body_html, body_text: f.body_text } )
				.then( ( r ) => setPreview( r.data ) )
				.catch( () => {} )
				.finally( () => setPreviewing( false ) );
		}, 450 );
		return () => clearTimeout( h );
	}, [ tkey, t, f.subject, f.preview, f.body_html, f.body_text ] );

	const insert = ( ph: string ) => {
		const token = `{${ ph }}`;
		const target = lastFocus.current;
		const el = target === 'html' ? htmlRef.current : target === 'text' ? textRef.current : target === 'subject' ? subjectRef.current : null;
		const key = target === 'html' ? 'body_html' : target === 'text' ? 'body_text' : target === 'subject' ? 'subject' : 'preview';
		const cur = String( f[ key as keyof typeof f ] ?? '' );
		const start = el?.selectionStart ?? cur.length;
		const end = el?.selectionEnd ?? cur.length;
		const next = cur.slice( 0, start ) + token + cur.slice( end );
		setF( { ...f, [ key ]: next } );
		requestAnimationFrame( () => {
			if ( el ) {
				el.focus();
				el.setSelectionRange( start + token.length, start + token.length );
			}
		} );
	};

	const dirty = !! t && ( f.subject !== t.subject || f.preview !== t.preview || f.body_html !== ( t.body_html || '' ) || f.body_text !== ( t.body_text || '' ) || f.enabled !== t.enabled );

	return (
		<>
			<EntityDrawer
				open={ !! tkey }
				onClose={ onClose }
				title={ t?.name || 'Email template' }
				badges={ t && ( t.customised ? <Chip tone="primary">Customised</Chip> : <Chip tone="neutral">Default</Chip> ) }
				subtitle={ t ? `${ t.key } · updated ${ relativeTime( t.updated_at ) }` : undefined }
				width={ 1180 }
				footer={
					<>
						<Btn
							variant="primary"
							busy={ busy }
							disabled={ ! dirty }
							onClick={ async () => {
								setBusy( true );
								setErr( null );
								try {
									const r = await api.patch< EmailTemplateRow >( `email-templates/${ tkey }`, f );
									res.setData( () => r.data );
									toast.success( 'Template saved' );
									onSaved();
								} catch ( e ) {
									setErr( e );
									toast.error( e );
								} finally {
									setBusy( false );
								}
							} }
						>
							Save template
						</Btn>
						<Btn icon="send" onClick={ () => setTestOpen( true ) }>
							Send test
						</Btn>
						<span className="wvm-a-spacer" />
						{ dirty && <span className="wvm-a-dirty">Unsaved changes</span> }
						<Btn variant="ghost" icon="restore" onClick={ () => setResetOpen( true ) } disabled={ ! t?.customised && ! dirty }>
							Reset to default
						</Btn>
					</>
				}
			>
				{ res.error && <ErrorState error={ res.error } onRetry={ res.reload } /> }
				{ ! t && ! res.error && <Loading /> }
				{ t && (
					<div className="wvm-a-tpl">
						<div className="wvm-a-tpl__form">
							<Toggle label="Enabled" checked={ f.enabled } onChange={ ( enabled ) => setF( { ...f, enabled } ) } help="Disabled templates are never sent." />
							<div className={ cx( 'wvm-a-field', fieldError( err, 'subject' ) && 'has-error' ) }>
								<label className="wvm-a-label" htmlFor="wvm-tpl-subject">
									Subject
								</label>
								<input id="wvm-tpl-subject" ref={ subjectRef } className="wvm-a-input" value={ f.subject } onFocus={ () => ( lastFocus.current = 'subject' ) } onChange={ ( e ) => setF( { ...f, subject: e.target.value } ) } />
								{ fieldError( err, 'subject' ) && <p className="wvm-a-help is-error">{ fieldError( err, 'subject' ) }</p> }
							</div>
							<div className="wvm-a-field">
								<label className="wvm-a-label" htmlFor="wvm-tpl-preview">
									Preview text <Muted>— shown after the subject in most inboxes</Muted>
								</label>
								<input id="wvm-tpl-preview" className="wvm-a-input" value={ f.preview } onFocus={ () => ( lastFocus.current = 'preview' ) } onChange={ ( e ) => setF( { ...f, preview: e.target.value } ) } />
							</div>
							<div className="wvm-a-placeholders">
								<span>
									<Icon name="sparkles" size={ 13 } /> Insert at cursor
								</span>
								<div>
									{ ( t.placeholders || config.placeholders ).map( ( p ) => (
										<button key={ p } type="button" onMouseDown={ ( e ) => e.preventDefault() } onClick={ () => insert( p ) }>
											{ `{${ p }}` }
										</button>
									) ) }
								</div>
							</div>
							<Tabs
								variant="segmented"
								value={ mode }
								onChange={ ( m ) => setMode( m as 'html' | 'text' ) }
								tabs={ [
									{ key: 'html', label: 'HTML body' },
									{ key: 'text', label: 'Plain-text body' },
								] }
							/>
							{ mode === 'html' ? (
								<textarea ref={ htmlRef } className="wvm-a-code" rows={ 16 } value={ f.body_html } onFocus={ () => ( lastFocus.current = 'html' ) } onChange={ ( e ) => setF( { ...f, body_html: e.target.value } ) } spellCheck={ false } />
							) : (
								<textarea ref={ textRef } className="wvm-a-code" rows={ 16 } value={ f.body_text } onFocus={ () => ( lastFocus.current = 'text' ) } onChange={ ( e ) => setF( { ...f, body_text: e.target.value } ) } />
							) }
							<p className="wvm-a-help">
								Use <code>[button url="{ '{dashboard_url}' }"]Label[/button]</code> for a branded button. HTML is sanitised on save.
							</p>
						</div>
						<div className="wvm-a-tpl__preview">
							<div className="wvm-a-tpl__previewbar">
								<span className="wvm-a-tpl__live">
									<i className={ previewing ? 'is-busy' : '' } /> { previewing ? 'Rendering…' : 'Live preview · sample data' }
								</span>
								<Tabs
									variant="segmented"
									value={ device }
									onChange={ ( d ) => setDevice( d as 'desktop' | 'mobile' ) }
									tabs={ [
										{ key: 'desktop', label: 'Desktop' },
										{ key: 'mobile', label: 'Mobile' },
									] }
								/>
							</div>
							<div className="wvm-a-inbox-preview">
								<div className="wvm-a-inbox-preview__from">
									<span className="wvm-a-avatar is-brand">W</span>
									<div>
										<strong>{ preview?.subject || f.subject }</strong>
										<span>{ f.preview }</span>
									</div>
								</div>
							</div>
							<div className={ cx( 'wvm-a-tpl__frame', device === 'mobile' && 'is-mobile' ) }>
								{ preview ? <iframe title="Email preview" srcDoc={ preview.html } sandbox="" /> : <Loading label="Rendering preview…" /> }
							</div>
						</div>
					</div>
				) }
			</EntityDrawer>
			<FormModal
				open={ testOpen }
				title="Send a test email"
				size="small"
				submitLabel="Send test"
				canSubmit={ /.+@.+/.test( to ) }
				description="Sends the saved version of this template with sample data. Save first to test your edits."
				onClose={ () => setTestOpen( false ) }
				onSubmit={ async () => {
					await api.post( `email-templates/${ tkey }/test`, { to } );
					toast.success( `Test email queued to ${ to }` );
					setTestOpen( false );
				} }
			>
				<TextField label="Send to" type="email" value={ to } onChange={ setTo } autoFocus />
			</FormModal>
			<ConfirmModal
				open={ resetOpen }
				title="Reset to default"
				destructive
				confirmLabel="Reset template"
				onClose={ () => setResetOpen( false ) }
				onConfirm={ async () => {
					const r = await api.post< EmailTemplateRow >( `email-templates/${ tkey }/reset` );
					res.setData( () => r.data );
					setResetOpen( false );
					toast.success( 'Template reset to the default copy' );
					onSaved();
				} }
			>
				<p>Your customised subject and body are replaced with the default WitsValley copy. This is recorded in the audit log.</p>
			</ConfirmModal>
		</>
	);
}
