/* Settings › Popup: the membership popup (free-book offer), its triggers, a live preview and conversion stats. */
import { useState } from "react";
import "../../../popup/popup.css";
import { config } from "../../lib/config";
import { fieldError } from "../../lib/api";
import { useApi } from "../../hooks/useApi";
import { useToast } from "../../components/Toasts";
import { Btn, Chip, Notice2, Skeleton, Tabs, cx } from "../../components/ui";
import { Icon } from "../../components/Icon";
import {
  TextField,
  TextareaField,
  SelectField,
  Toggle,
  FieldGrid,
  Checkbox,
} from "../../components/fields";
import { ListEditor, TokenInput } from "../../components/editors";
import type { KitStatus } from "../../lib/types";
import { useSettingsGroup, SettingsFrame, Section } from "./shared";

interface PopupValues extends Record<string, unknown> {
  enabled: boolean;
  mode: "join" | "email";
  eyebrow: string;
  headline: string;
  headline_b: string;
  body: string;
  bullets: string[];
  button: string;
  email_button: string;
  note: string;
  image_url: string;
  book_url: string;
  success_title: string;
  success_body: string;
  email_success: string;
  offer_tag: string;
  lead_tag: string;
  upgrade_enabled: boolean;
  upgrade_eyebrow: string;
  upgrade_headline: string;
  upgrade_body: string;
  upgrade_button: string;
  upgrade_plan: string;
  delay_seconds: number;
  scroll_percent: number;
  exit_intent: boolean;
  on_paywall: boolean;
  min_pageviews: number;
  dismiss_days: number;
  post_types: string[];
  exclude_paths: string[];
}

type Row = Record<
  "view" | "close" | "signup" | "lead" | "upgrade_click" | "paid" | "rate",
  number
>;
interface Stats {
  days: number;
  variants: Record<"a" | "b" | "upgrade", Row>;
}

interface WpMediaFrame {
  on: (ev: string, fn: () => void) => void;
  open: () => void;
  state: () => {
    get: (k: string) => { first: () => { toJSON: () => { url: string } } };
  };
}

function pickMedia(
  title: string,
  type: string | undefined,
  onPick: (url: string) => void,
  onMissing: () => void,
) {
  const wp = (
    window as unknown as {
      wp?: { media?: (o: Record<string, unknown>) => WpMediaFrame };
    }
  ).wp;
  if (!wp?.media) {
    onMissing();
    return;
  }
  const frame = wp.media({
    title,
    button: { text: "Use this file" },
    multiple: false,
    library: type ? { type } : undefined,
  });
  frame.on("select", () =>
    onPick(frame.state().get("selection").first().toJSON().url),
  );
  frame.open();
}

const previewUrl = (kind: "join" | "upgrade") =>
  `${config.homeUrl}${
    config.homeUrl.includes("?") ? "&" : "?"
  }wvm_popup=preview${kind === "upgrade" ? "&wvm_popup_kind=upgrade" : ""}`;

/* Static replica of the front-end popup (same CSS), updated as you type. */
function Preview({
  v,
  kind,
  variant,
}: {
  v: PopupValues;
  kind: "join" | "upgrade";
  variant: "a" | "b";
}) {
  const email = v.mode === "email";
  const headline = variant === "b" && v.headline_b ? v.headline_b : v.headline;
  return (
    <div className="wvm-pop is-open is-preview" data-kind={kind}>
      <div className="wvm-pop__card">
        <span className="wvm-pop__close" aria-hidden="true">
          <Icon name="x" size={18} />
        </span>
        <div className={cx("wvm-pop__art", v.image_url && "has-image")}>
          {v.image_url ? (
            <img src={v.image_url} alt="" />
          ) : (
            <div className="wvm-pop__book">
              <span className="wvm-pop__book-mark">
                <Icon
                  name={kind === "upgrade" ? "sparkles" : "bookmark"}
                  size={26}
                />
              </span>
              <strong>{config.siteName}</strong>
              <em>{kind === "upgrade" ? v.upgrade_eyebrow : v.eyebrow}</em>
            </div>
          )}
        </div>
        <div className="wvm-pop__body">
          {kind === "upgrade" ? (
            <>
              {v.upgrade_eyebrow && (
                <span className="wvm-pop__eyebrow">{v.upgrade_eyebrow}</span>
              )}
              <h2 className="wvm-pop__title">{v.upgrade_headline}</h2>
              {v.upgrade_body && (
                <p className="wvm-pop__text">{v.upgrade_body}</p>
              )}
              <div className="wvm-pop__actions">
                <span className="wvm-pop__btn">
                  {v.upgrade_button} <Icon name="external" size={16} />
                </span>
                <span className="wvm-pop__ghost">Not now</span>
              </div>
            </>
          ) : (
            <>
              {v.eyebrow && (
                <span className="wvm-pop__eyebrow">{v.eyebrow}</span>
              )}
              <h2 className="wvm-pop__title">{headline}</h2>
              {v.body && <p className="wvm-pop__text">{v.body}</p>}
              {v.bullets.length > 0 && (
                <ul className="wvm-pop__list">
                  {v.bullets.map((b, i) => (
                    <li key={i}>
                      <span className="wvm-pop__tick">
                        <Icon name="check" size={12} strokeWidth={2.6} />
                      </span>
                      {b}
                    </li>
                  ))}
                </ul>
              )}
              <div className="wvm-pop__form">
                <div className="wvm-pop__row">
                  <div className="wvm-pop__field">
                    <label>First name</label>
                    <span className="wvm-pop__input" />
                  </div>
                  <div className="wvm-pop__field">
                    <label>Email</label>
                    <span className="wvm-pop__input" />
                  </div>
                </div>
                {!email && (
                  <div className="wvm-pop__field">
                    <label>Create a password</label>
                    <span className="wvm-pop__input" />
                  </div>
                )}
                <span className="wvm-pop__btn is-block">
                  {email ? v.email_button : v.button} →
                </span>
                <p className="wvm-pop__legal">
                  {email
                    ? "We’ll email you the book and a short series of guides. Unsubscribe any time."
                    : "By joining you agree to the terms of membership and privacy policy."}
                </p>
              </div>
              <p className="wvm-pop__foot">
                {v.note && <span>{v.note}</span>}
                {!email && (
                  <span>
                    Already a member?{" "}
                    <a href="#login" onClick={(e) => e.preventDefault()}>
                      Log in
                    </a>
                  </span>
                )}
              </p>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

function StatsCard() {
  const [days, setDays] = useState(30);
  const res = useApi<Stats>("popup/stats", { days });
  const d = res.data;
  const pct = (n: number) => `${n.toFixed(1)}%`;
  const ab = !!d && d.variants.b.view > 0;
  const rows: [string, Row][] = d
    ? ([
        [ab ? "Offer · headline A" : "Free-book offer", d.variants.a],
        ...(ab ? [["Offer · headline B", d.variants.b]] : []),
        ["Upgrade (free members)", d.variants.upgrade],
      ] as [string, Row][])
    : [];
  return (
    <Section
      title="Results"
      description="Views, closes and sign-ups from the popup. “Later paid” counts popup sign-ups who have since bought a paid plan."
    >
      <div className="wvm-a-inline">
        <SelectField
          label="Period"
          value={String(days)}
          onChange={(x) => setDays(Number(x))}
          options={[
            { value: "7", label: "Last 7 days" },
            { value: "30", label: "Last 30 days" },
            { value: "90", label: "Last 90 days" },
          ]}
        />
      </div>
      {!d ? (
        <Skeleton h={120} r={12} />
      ) : (
        <div className="wvm-a-popstats">
          <div className="wvm-a-popstats__head">
            <span>Version</span>
            <span>Views</span>
            <span>Closed</span>
            <span>Sign-ups</span>
            <span>Conversion</span>
            <span>Later paid</span>
          </div>
          {rows.map(([label, r]) => {
            const up = label.startsWith("Upgrade");
            return (
              <div key={label} className="wvm-a-popstats__row">
                <strong>{label}</strong>
                <span>{r.view}</span>
                <span>{r.close}</span>
                <span>
                  {up ? `${r.upgrade_click} clicks` : r.signup + r.lead}
                </span>
                <span>
                  <Chip
                    tone={
                      r.rate >= 3 ? "success" : r.view ? "neutral" : "neutral"
                    }
                  >
                    {pct(r.rate)}
                  </Chip>
                </span>
                <span>{up ? "—" : r.paid}</span>
              </div>
            );
          })}
        </div>
      )}
    </Section>
  );
}

export function PopupSettings() {
  const toast = useToast();
  const s = useSettingsGroup<PopupValues>("popup");
  const kit = useApi<KitStatus>("kit/status");
  const [tab, setTab] = useState("offer");
  const [previewKind, setPreviewKind] = useState<"join" | "upgrade">("join");
  const [variant, setVariant] = useState<"a" | "b">("a");
  const v = s.values;
  const missingMedia = () =>
    toast.info("The media library isn’t available here. Paste a URL instead.");
  return (
    <SettingsFrame
      title="Popup"
      description="A membership popup on your blog posts and pages: offer the free book, get a free member. Free members see a Premium upgrade instead; paying members never see it."
      state={tab === "results" ? undefined : s}
      actions={
        <>
          <Btn
            variant="ghost"
            icon="eye"
            href={previewUrl("join")}
            target="_blank"
          >
            Preview on site
          </Btn>
        </>
      }
    >
      {v && (
        <div className={cx("wvm-a-popstatus", v.enabled ? "is-on" : "is-off")}>
          <span className="wvm-a-kitstatus__dot" />
          <div>
            <strong>
              {v.enabled ? "The popup is live" : "The popup is off"}
            </strong>
            <span>
              {v.enabled
                ? `${
                    v.mode === "email" ? "Email only" : "Join free"
                  } · shows after ${v.delay_seconds}s or ${
                    v.scroll_percent
                  }% scroll${v.exit_intent ? ", or when leaving" : ""}`
                : "Set up the offer, check the preview, then switch it on."}
            </span>
          </div>
          <Toggle
            label="Show the popup"
            checked={v.enabled}
            onChange={(enabled) => s.set({ enabled })}
          />
        </div>
      )}
      <Tabs
        variant="segmented"
        value={tab}
        onChange={setTab}
        tabs={[
          { key: "offer", label: "Offer" },
          { key: "upgrade", label: "Upgrade" },
          { key: "triggers", label: "When & where" },
          { key: "results", label: "Results" },
        ]}
      />
      {tab === "results" && <StatsCard />}
      {v && tab !== "results" && (
        <div className="wvm-a-popedit">
          <div className="wvm-a-popedit__grid">
            <div className="wvm-a-popedit__form">
              {tab === "offer" && (
                <>
                  <Section
                    title="Sign-up"
                    description="Join free creates a free membership (dashboard, free lessons, upgrade path). Email only just adds them to Kit."
                  >
                    <SelectField
                      label="What the popup asks for"
                      value={v.mode}
                      onChange={(mode) =>
                        s.set({ mode: mode as PopupValues["mode"] })
                      }
                      options={[
                        {
                          value: "join",
                          label:
                            "Join free: name, email, password (recommended)",
                        },
                        {
                          value: "email",
                          label: "Email only: name and email, sent to Kit",
                        },
                      ]}
                    />
                    {v.mode === "email" && kit.data && !kit.data.connected && (
                      <Notice2 tone="warning" title="Kit isn’t connected">
                        Email-only sign-ups are sent to Kit. Connect it in
                        Settings › Kit first.
                      </Notice2>
                    )}
                  </Section>
                  <Section
                    title="The free book"
                    description="Upload the book (PDF) and its cover to the Media Library. Join-free members get a download button right away; Kit sends your guide emails."
                  >
                    <div className="wvm-a-mediafield">
                      <TextField
                        label="Book file (PDF) URL"
                        value={v.book_url}
                        onChange={(book_url) => s.set({ book_url })}
                        placeholder="https://…/free-book.pdf"
                        error={fieldError(s.error, "book_url")}
                      />
                      <Btn
                        icon="upload"
                        onClick={() =>
                          pickMedia(
                            "Choose the free book",
                            undefined,
                            (book_url) => s.set({ book_url }),
                            missingMedia,
                          )
                        }
                      >
                        Media Library
                      </Btn>
                    </div>
                    <div className="wvm-a-mediafield">
                      <TextField
                        label="Book cover image URL"
                        value={v.image_url}
                        onChange={(image_url) => s.set({ image_url })}
                        placeholder="Leave empty for a branded book graphic"
                      />
                      <Btn
                        icon="upload"
                        onClick={() =>
                          pickMedia(
                            "Choose the book cover",
                            "image",
                            (image_url) => s.set({ image_url }),
                            missingMedia,
                          )
                        }
                      >
                        Media Library
                      </Btn>
                    </div>
                    <FieldGrid>
                      <TextField
                        label="Kit tag for the offer"
                        value={v.offer_tag}
                        onChange={(offer_tag) =>
                          s.set({ offer_tag: offer_tag.toUpperCase() })
                        }
                        help="Start your guide-email sequence on this tag in Kit."
                        error={fieldError(s.error, "offer_tag")}
                      />
                      {v.mode === "email" && (
                        <TextField
                          label="Kit tag for email-only leads"
                          value={v.lead_tag}
                          onChange={(lead_tag) =>
                            s.set({ lead_tag: lead_tag.toUpperCase() })
                          }
                          help="Use it for a “become a member” follow-up."
                          error={fieldError(s.error, "lead_tag")}
                        />
                      )}
                    </FieldGrid>
                  </Section>
                  <Section
                    title="Words"
                    description="Short and specific converts best: what they get, and that it’s free."
                  >
                    <FieldGrid>
                      <TextField
                        label="Small label"
                        value={v.eyebrow}
                        onChange={(eyebrow) => s.set({ eyebrow })}
                      />
                      <TextField
                        label={v.mode === "email" ? "Button" : "Button"}
                        value={v.mode === "email" ? v.email_button : v.button}
                        onChange={(x) =>
                          s.set(
                            v.mode === "email"
                              ? { email_button: x }
                              : { button: x },
                          )
                        }
                      />
                    </FieldGrid>
                    <TextField
                      label="Headline"
                      value={v.headline}
                      onChange={(headline) => s.set({ headline })}
                    />
                    <TextField
                      label="Second headline to test (optional)"
                      value={v.headline_b}
                      onChange={(headline_b) => s.set({ headline_b })}
                      help="Half of visitors see this one instead. Compare them under Results, then keep the winner."
                    />
                    <TextareaField
                      label="Text"
                      rows={3}
                      value={v.body}
                      onChange={(body) => s.set({ body })}
                    />
                    <div className="wvm-a-field">
                      <span className="wvm-a-label">What they get</span>
                      <ListEditor
                        value={v.bullets}
                        onChange={(bullets) => s.set({ bullets })}
                        placeholder="Add a point…"
                      />
                    </div>
                    <TextField
                      label="Small print"
                      value={v.note}
                      onChange={(note) => s.set({ note })}
                    />
                  </Section>
                  <Section title="After they sign up">
                    {v.mode === "join" ? (
                      <>
                        <TextField
                          label="Title"
                          value={v.success_title}
                          onChange={(success_title) => s.set({ success_title })}
                        />
                        <TextareaField
                          label="Text"
                          rows={2}
                          value={v.success_body}
                          onChange={(success_body) => s.set({ success_body })}
                          help="Shown with a “Download your free book” button (when a book file is set) and “Go to my dashboard”."
                        />
                      </>
                    ) : (
                      <TextareaField
                        label="Thank-you text"
                        rows={2}
                        value={v.email_success}
                        onChange={(email_success) => s.set({ email_success })}
                      />
                    )}
                  </Section>
                </>
              )}
              {tab === "upgrade" && (
                <Section
                  title="Upgrade popup"
                  description="Shown to logged-in free members instead of the free offer, with the same timing. The button opens the pricing page on the chosen plan."
                >
                  <Toggle
                    label="Show free members an upgrade popup"
                    checked={v.upgrade_enabled}
                    onChange={(upgrade_enabled) => s.set({ upgrade_enabled })}
                  />
                  <FieldGrid>
                    <TextField
                      label="Small label"
                      value={v.upgrade_eyebrow}
                      onChange={(upgrade_eyebrow) => s.set({ upgrade_eyebrow })}
                    />
                    <TextField
                      label="Plan to open"
                      value={v.upgrade_plan}
                      onChange={(upgrade_plan) => s.set({ upgrade_plan })}
                      help="The plan’s slug, e.g. premium or basic."
                    />
                  </FieldGrid>
                  <TextField
                    label="Headline"
                    value={v.upgrade_headline}
                    onChange={(upgrade_headline) => s.set({ upgrade_headline })}
                  />
                  <TextareaField
                    label="Text"
                    rows={3}
                    value={v.upgrade_body}
                    onChange={(upgrade_body) => s.set({ upgrade_body })}
                  />
                  <TextField
                    label="Button"
                    value={v.upgrade_button}
                    onChange={(upgrade_button) => s.set({ upgrade_button })}
                  />
                </Section>
              )}
              {tab === "triggers" && (
                <>
                  <Section
                    title="When it appears"
                    description="Whichever happens first. It shows once per visit at most."
                  >
                    <FieldGrid>
                      <TextField
                        label="After (seconds on the page)"
                        type="number"
                        min={0}
                        max={600}
                        value={v.delay_seconds}
                        onChange={(x) => s.set({ delay_seconds: Number(x) })}
                        help="0 = off."
                      />
                      <TextField
                        label="Or after scrolling (%)"
                        type="number"
                        min={0}
                        max={100}
                        value={v.scroll_percent}
                        onChange={(x) => s.set({ scroll_percent: Number(x) })}
                        help="0 = off."
                      />
                    </FieldGrid>
                    <Toggle
                      label="When someone is about to leave (desktop)"
                      checked={v.exit_intent}
                      onChange={(exit_intent) => s.set({ exit_intent })}
                    />
                    <Toggle
                      label="When a visitor reaches a members-only paywall"
                      checked={v.on_paywall}
                      onChange={(on_paywall) => s.set({ on_paywall })}
                      help="The strongest moment to ask. Ignores the page-view minimum below."
                    />
                    <FieldGrid>
                      <TextField
                        label="Start from page view number"
                        type="number"
                        min={1}
                        max={20}
                        value={v.min_pageviews}
                        onChange={(x) => s.set({ min_pageviews: Number(x) })}
                        help="1 = from the first page. 2 = wait until they read a second page."
                      />
                      <TextField
                        label="After closing, hide for (days)"
                        type="number"
                        min={0}
                        max={365}
                        value={v.dismiss_days}
                        onChange={(x) => s.set({ dismiss_days: Number(x) })}
                      />
                    </FieldGrid>
                  </Section>
                  <Section
                    title="Where"
                    description="Never on the membership, dashboard, enterprise, payment, cart or checkout pages."
                  >
                    <div className="wvm-a-field">
                      <span className="wvm-a-label">Show on</span>
                      <div className="wvm-a-checkpills">
                        {config.postTypes.map((t) => (
                          <Checkbox
                            key={t.name}
                            label={t.label}
                            checked={v.post_types.includes(t.name)}
                            onChange={(on) =>
                              s.set({
                                post_types: on
                                  ? [...v.post_types, t.name]
                                  : v.post_types.filter((x) => x !== t.name),
                              })
                            }
                          />
                        ))}
                      </div>
                      <p className="wvm-a-help">
                        With Posts ticked, it also shows on the blog, category
                        and search pages.
                      </p>
                    </div>
                    <TokenInput
                      label="Never on paths starting with"
                      value={v.exclude_paths}
                      onChange={(x) => s.set({ exclude_paths: x as string[] })}
                      placeholder="/contact"
                      help="Type a path and press Enter."
                    />
                  </Section>
                </>
              )}
            </div>
            <aside className="wvm-a-popedit__preview">
              <div className="wvm-a-popedit__bar">
                <Tabs
                  variant="segmented"
                  value={previewKind}
                  onChange={(k) => setPreviewKind(k as "join" | "upgrade")}
                  tabs={[
                    { key: "join", label: "Visitors" },
                    { key: "upgrade", label: "Free members" },
                  ]}
                />
                {previewKind === "join" && v.headline_b && (
                  <button
                    type="button"
                    className="wvm-a-linkbtn"
                    onClick={() => setVariant(variant === "a" ? "b" : "a")}
                  >
                    Showing headline {variant.toUpperCase()} · switch
                  </button>
                )}
              </div>
              <div className="wvm-a-popedit__frame">
                <Preview v={v} kind={previewKind} variant={variant} />
              </div>
              <a
                className="wvm-a-linkbtn"
                href={previewUrl(previewKind)}
                target="_blank"
                rel="noreferrer"
              >
                <Icon name="external" size={12} /> Try it on the site (saved
                version)
              </a>
            </aside>
          </div>
        </div>
      )}
    </SettingsFrame>
  );
}
