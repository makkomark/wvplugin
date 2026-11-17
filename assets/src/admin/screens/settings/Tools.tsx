import { useState } from 'react';
import { useApi } from '../../hooks/useApi';
import { api, downloadJson } from '../../lib/api';
import { config } from '../../lib/config';
import type { HealthCheck } from '../../../shared/types';
import { relativeTime, formatDateTime } from '../../../shared/format';
import { Btn, Card, Notice2, Skeleton, DescList, Mono } from '../../components/ui';
import { Icon } from '../../components/Icon';
import { HealthPanel } from '../../components/widgets';
import { ConfirmModal } from '../../components/overlays';
import { TextField, Toggle, FieldGrid } from '../../components/fields';
import { useToast } from '../../components/Toasts';
import { useSettingsGroup, SettingsFrame, Section } from './shared';

interface ToolsValues extends Record< string, unknown > {
	backup_enabled: boolean;
	backup_keep_days: number;
	s3_endpoint: string;
	s3_bucket: string;
	s3_region: string;
	s3_key: string;
	s3_secret: string;
	s3_secret_set?: boolean;
	last_backup_at: string | null;
}

interface ExportFile { format: string; version: string; exported_at: string; settings?: Record< string, unknown >; plans?: unknown[]; templates?: unknown[] }

export function ToolsSettings() {
	const toast = useToast();
	const s = useSettingsGroup< ToolsValues >( 'tools' );
	const health = useApi< { status: 'ok' | 'warning' | 'error'; checks: HealthCheck[] } >( 'tools/health' );
	const [ exporting, setExporting ] = useState( false );
	const [ importing, setImporting ] = useState< ExportFile | null >( null );
	const [ importName, setImportName ] = useState( '' );
	const [ importResult, setImportResult ] = useState< Record< string, number > | null >( null );
	const [ backingUp, setBackingUp ] = useState( false );
	const [ backup, setBackup ] = useState< { file: string; bytes: number; s3: string | null } | null >( null );
	const v = s.values;
	return (
		<SettingsFrame title="Tools" description="Move configuration between sites, check plugin health and take backups." state={ s }>
			<div className="wvm-a-toolgrid">
				<Card title="Export configuration" icon="download" subtitle="Settings, plans and email templates as JSON. Secrets are never included.">
					<Btn
						variant="primary"
						icon="download"
						busy={ exporting }
						onClick={ async () => {
							setExporting( true );
							try {
								const r = await api.post< ExportFile >( 'tools/export' );
								downloadJson( r.data, `witsvalley-config-${ new Date().toISOString().slice( 0, 10 ) }.json` );
								toast.success( 'Configuration exported' );
							} catch ( e ) {
								toast.error( e );
							} finally {
								setExporting( false );
							}
						} }
					>
						Download export
					</Btn>
				</Card>
				<Card title="Import configuration" icon="upload" subtitle="Merges settings, updates plans by slug and replaces template copy.">
					<label className="wvm-a-filebtn is-large">
						<Icon name="upload" size={ 16 } /> Choose export file…
						<input
							type="file"
							accept="application/json,.json"
							onChange={ async ( e ) => {
								const file = e.target.files?.[ 0 ];
								e.target.value = '';
								if ( ! file ) {
									return;
								}
								try {
									const data = JSON.parse( await file.text() ) as ExportFile;
									if ( data.format !== 'wv-members-config' ) {
										toast.error( 'That file isn’t a WitsValley Members export.' );
										return;
									}
									setImportName( file.name );
									setImportResult( null );
									setImporting( data );
								} catch ( err ) {
									toast.error( 'Couldn’t read that file — is it valid JSON?' );
								}
							} }
						/>
					</label>
					{ importResult && (
						<Notice2 tone="success" title="Import complete">
							{ importResult.settings } settings groups · { importResult.plans } plans · { importResult.templates } templates
						</Notice2>
					) }
				</Card>
				<Card
					title="Backups"
					icon="database"
					subtitle={ v?.last_backup_at ? `Last backup ${ relativeTime( v.last_backup_at ) }` : 'No backup yet' }
					actions={
						<Btn
							icon="database"
							busy={ backingUp }
							onClick={ async () => {
								setBackingUp( true );
								try {
									const r = await api.post< { file: string; bytes: number; s3: string | null } >( 'tools/backup-now' );
									setBackup( r.data );
									toast.success( 'Backup created' );
									s.res.reload();
									health.reload();
								} catch ( e ) {
									toast.error( e );
								} finally {
									setBackingUp( false );
								}
							} }
						>
							Back up now
						</Btn>
					}
				>
					{ backup && (
						<Notice2 tone="success" title="Backup written">
							<Mono>{ backup.file }</Mono> · { ( backup.bytes / 1024 ).toFixed( 1 ) } KB{ backup.s3 ? ` · uploaded to ${ backup.s3 }` : ' · S3 not configured' }
						</Notice2>
					) }
					<p className="wvm-a-help">All plugin tables are exported daily to a protected uploads folder (gzipped JSON) and optionally copied to S3-compatible storage.</p>
				</Card>
				<Card title="Background jobs" icon="activity" subtitle="Renewals, reminders, Kit sync and emails run on Action Scheduler.">
					<Btn icon="external" href={ config.actionSchedulerUrl }>
						Open Action Scheduler
					</Btn>
				</Card>
			</div>

			{ v && (
				<Section title="Backup settings" description="Optional off-site copy to any S3-compatible bucket (AWS, Wasabi, Backblaze, R2).">
					<FieldGrid>
						<Toggle label="Daily backup" checked={ v.backup_enabled } onChange={ ( backup_enabled ) => s.set( { backup_enabled } ) } />
						<TextField label="Keep backups for (days)" type="number" min={ 1 } value={ v.backup_keep_days } onChange={ ( n ) => s.set( { backup_keep_days: Number( n ) || 1 } ) } />
						<TextField label="S3 endpoint" value={ v.s3_endpoint } onChange={ ( s3_endpoint ) => s.set( { s3_endpoint } ) } placeholder="https://s3.eu-west-1.amazonaws.com" />
						<TextField label="Bucket" value={ v.s3_bucket } onChange={ ( s3_bucket ) => s.set( { s3_bucket } ) } />
						<TextField label="Region" value={ v.s3_region } onChange={ ( s3_region ) => s.set( { s3_region } ) } />
						<TextField label="Access key" value={ v.s3_key } onChange={ ( s3_key ) => s.set( { s3_key } ) } />
						<TextField label="Secret key" type="password" value={ v.s3_secret } onChange={ ( s3_secret ) => s.set( { s3_secret } ) } help={ v.s3_secret_set ? 'Saved (encrypted). Type to replace.' : 'Not set' } />
					</FieldGrid>
				</Section>
			) }

			<Section title="Health" description="The same checks shown on Home. Alerts are emailed when webhooks go silent for 48h, failed jobs pass 10 or Kit errors pass 5.">
				<Card actions={ <Btn size="compact" variant="ghost" icon="refresh" onClick={ health.reload }>Re-run checks</Btn> } title="Checks">
					{ health.data ? <HealthPanel checks={ health.data.checks } status={ health.data.status } /> : <Skeleton h={ 260 } r={ 12 } /> }
				</Card>
			</Section>

			<ConfirmModal
				open={ !! importing }
				title="Import configuration?"
				confirmLabel="Import now"
				onClose={ () => setImporting( null ) }
				onConfirm={ async () => {
					const r = await api.post< Record< string, number > >( 'tools/import', { data: importing } );
					setImportResult( r.data );
					setImporting( null );
					toast.success( 'Configuration imported' );
					s.res.reload();
				} }
			>
				{ importing && (
					<>
						<DescList
							items={ [
								[ 'File', importName ],
								[ 'Exported', importing.exported_at ? formatDateTime( importing.exported_at ) : '—' ],
								[ 'Settings groups', Object.keys( importing.settings || {} ).length ],
								[ 'Plans', ( importing.plans || [] ).length ],
								[ 'Email templates', ( importing.templates || [] ).length ],
								[ 'Plugin version', importing.version ],
							] }
						/>
						<Notice2 tone="warning">Existing values are overwritten. API keys and secrets are kept as they are. This is recorded in the audit log.</Notice2>
					</>
				) }
			</ConfirmModal>
		</SettingsFrame>
	);
}
