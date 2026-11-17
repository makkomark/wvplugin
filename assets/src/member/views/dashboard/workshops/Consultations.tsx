/* 1:1 consultation requests (Premium+): form + list; upsell for lower levels. */
import { useState } from '@wordpress/element';
import type { FormEvent } from 'react';
import type { Consultation } from '../../../../shared/types';
import { relativeTime } from '../../../../shared/format';
import { api, errorMessage, fieldErrors } from '../../../lib/api';
import { formatWhen } from '../../../lib/util';
import { useAsync } from '../../../hooks/useAsync';
import { Card, CardHeader, SectionTitle, StatusChip } from '../../../components/Card';
import { Button } from '../../../components/Button';
import { Input, Textarea } from '../../../components/Field';
import { Banner, EmptyState, Skeleton } from '../../../components/Feedback';
import { Icon } from '../../../components/Icon';
import { useToast } from '../../../components/Toast';
import { useDashboard, useTz } from '../context';

export function Consultations() {
	const { overview, navigate } = useDashboard();
	const eligible = overview.entitlements.level >= 2;
	const premium = overview.plans.find( ( p ) => p.level === 2 && ! p.is_quoted );

	return (
		<section className="wvm-section">
			<SectionTitle title="1:1 consultations" subtitle="Get personal advice from a WitsValley expert on your money goals." />
			{ eligible ? (
				<ConsultationsInner />
			) : (
				<Card variant="soft" pad="lg" className="wvm-upsell-card">
					<span className="wvm-upsell-card__icon" aria-hidden="true"><Icon name="message" size={ 22 } /></span>
					<div className="wvm-upsell-card__body">
						<p className="wvm-h3">Consultations are included with { premium?.name || 'Premium' }</p>
						<p className="wvm-muted">Book a private session to plan savings, investments or your business finances.</p>
					</div>
					<Button onClick={ () => navigate( 'billing' ) } iconRight="arrow-right">Upgrade</Button>
				</Card>
			) }
		</section>
	);
}

function ConsultationsInner() {
	const tz = useTz();
	const toast = useToast();
	const list = useAsync< Consultation[] >( async () => ( await api.get< Consultation[] >( 'me/consultations' ) ).data, [] );
	const [ f, setF ] = useState( { topic: '', preferred_dates: '', notes: '' } );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ error, setError ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );

	const submit = async ( e: FormEvent ) => {
		e.preventDefault();
		setError( null );
		if ( ! f.topic.trim() ) {
			setErrors( { topic: 'What would you like to talk about?' } );
			return;
		}
		setBusy( true );
		try {
			const { data } = await api.post< Consultation >( 'me/consultations', f );
			list.setData( ( prev ) => [ data, ...( prev || [] ) ] );
			setF( { topic: '', preferred_dates: '', notes: '' } );
			setErrors( {} );
			toast.success( "Request sent — we'll confirm a time by email." );
		} catch ( err ) {
			setErrors( fieldErrors( err ) );
			setError( errorMessage( err ) );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="wvm-grid wvm-grid--consult">
			<Card pad="lg">
				<CardHeader icon="plus" title="Request a consultation" subtitle="We usually reply within 2 working days." />
				<form className="wvm-stack" onSubmit={ submit } noValidate>
					<Input label="Topic" placeholder="e.g. Building an emergency fund" value={ f.topic } onValue={ ( v ) => setF( { ...f, topic: v } ) } error={ errors.topic } maxLength={ 200 } required />
					<Input label="Preferred dates & times" placeholder="e.g. Weekday evenings next week" value={ f.preferred_dates } onValue={ ( v ) => setF( { ...f, preferred_dates: v } ) } error={ errors.preferred_dates } optional />
					<Textarea label="Notes" rows={ 3 } placeholder="Anything that helps us prepare" value={ f.notes } onValue={ ( v ) => setF( { ...f, notes: v } ) } error={ errors.notes } optional />
					{ error && <Banner tone="danger">{ error }</Banner> }
					<Button type="submit" loading={ busy } iconRight="arrow-right">Send request</Button>
				</form>
			</Card>
			<Card pad="lg">
				<CardHeader icon="message" title="Your requests" />
				{ list.loading && ! list.data && (
					<div className="wvm-stack">
						<Skeleton h={ 56 } r={ 14 } />
						<Skeleton h={ 56 } r={ 14 } />
					</div>
				) }
				{ list.error && ! list.data && <p className="wvm-muted">{ list.error }</p> }
				{ list.data && list.data.length === 0 && <EmptyState compact icon="message" title="No requests yet">Your consultation requests and their status will show here.</EmptyState> }
				{ list.data && list.data.length > 0 && (
					<ul className="wvm-list">
						{ list.data.map( ( c ) => (
							<li key={ c.id } className="wvm-list__item wvm-consult">
								<div className="wvm-consult__main">
									<p className="wvm-list__title">{ c.topic }</p>
									<p className="wvm-list__meta">
										{ c.status === 'scheduled' && c.scheduled_at ? <>Scheduled for { formatWhen( c.scheduled_at, tz ) }</> : <>Requested { relativeTime( c.created_at ) }</> }
									</p>
									{ c.status === 'scheduled' && c.meeting_url && (
										<a className="wvm-link wvm-small" href={ c.meeting_url } target="_blank" rel="noreferrer">
											Join meeting <Icon name="external" size={ 12 } />
										</a>
									) }
								</div>
								<StatusChip status={ c.status } label={ c.status === 'new' ? 'Requested' : undefined } />
							</li>
						) ) }
					</ul>
				) }
			</Card>
		</div>
	);
}
