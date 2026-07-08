import { createHash } from "node:crypto";
import nodemailer from "nodemailer";
import type Mail from "nodemailer/lib/mailer";
import { errorMessage, type FetchLike } from "@/lib/fetching";
import type { AlertReview } from "@/lib/ai-classifier";
import type { NodvarselAlert } from "@/lib/status";

export type NotificationResult = {
  state: "sent" | "skipped" | "error";
  reason: string;
  key: string;
};

type SendAlertNotificationOptions = {
  apiKey?: string;
  from?: string;
  to?: string;
  fetcher?: FetchLike;
  smtpHost?: string;
  smtpPassword?: string;
  smtpPort?: number;
  smtpSecure?: boolean;
  smtpTransporter?: MailTransporter;
  smtpUser?: string;
  dedupe?: boolean;
  now?: Date;
};

type MailTransporter = {
  sendMail(message: Mail.Options): Promise<unknown>;
};

const RESEND_EMAILS_URL = "https://api.resend.com/emails";
const DEFAULT_SMTP_HOST = "smtp.hostinger.com";
const DEFAULT_SMTP_PORT = 465;
const DEFAULT_SMTP_SECURE = true;
const DEFAULT_TO = "lyder2@mac.com";
const DEDUPE_TTL_MS = 24 * 60 * 60 * 1000;
const sentNotificationKeys = new Map<string, number>();

export async function sendAlertNotification(
  alert: NodvarselAlert,
  review: AlertReview,
  options: SendAlertNotificationOptions = {},
): Promise<NotificationResult> {
  const key = createNotificationKey(alert, review);
  const nowMs = (options.now ?? new Date()).getTime();

  if (options.dedupe !== false && isRecentlySent(key, nowMs)) {
    return {
      state: "skipped",
      reason: "Samme varsel/status er allerede varslet i denne serverprosessen.",
      key,
    };
  }

  const apiKey = options.apiKey ?? process.env.RESEND_API_KEY;
  const from =
    options.from ?? process.env.ALERT_EMAIL_FROM ?? process.env.SMTP_USER;
  const to = options.to ?? process.env.ALERT_EMAIL_TO ?? DEFAULT_TO;

  if (shouldUseSmtp(options)) {
    return sendWithSmtp(alert, review, {
      ...options,
      from,
      key,
      nowMs,
      to,
    });
  }

  return sendWithResend(alert, review, {
    apiKey,
    fetcher: options.fetcher,
    from,
    key,
    nowMs,
    to,
  });
}

export function shouldNotifyForReview(review: AlertReview): boolean {
  return (
    review.classification === "confirmed_yes" ||
    review.classification === "uncertain"
  );
}

async function sendWithSmtp(
  alert: NodvarselAlert,
  review: AlertReview,
  options: SendAlertNotificationOptions & {
    from?: string;
    key: string;
    nowMs: number;
    to: string;
  },
): Promise<NotificationResult> {
  const smtpUser = options.smtpUser ?? process.env.SMTP_USER;
  const smtpPassword = options.smtpPassword ?? process.env.SMTP_PASSWORD;
  const smtpHost = options.smtpHost ?? process.env.SMTP_HOST ?? DEFAULT_SMTP_HOST;
  const smtpPort =
    options.smtpPort ??
    parseOptionalInteger(process.env.SMTP_PORT) ??
    DEFAULT_SMTP_PORT;
  const smtpSecure =
    options.smtpSecure ??
    parseOptionalBoolean(process.env.SMTP_SECURE) ??
    DEFAULT_SMTP_SECURE;

  if (!smtpUser) {
    return {
      state: "skipped",
      reason: "SMTP_USER mangler, e-post ble ikke sendt.",
      key: options.key,
    };
  }

  if (!smtpPassword) {
    return {
      state: "skipped",
      reason: "SMTP_PASSWORD mangler, e-post ble ikke sendt.",
      key: options.key,
    };
  }

  if (!options.from) {
    return {
      state: "skipped",
      reason: "ALERT_EMAIL_FROM eller SMTP_USER mangler, e-post ble ikke sendt.",
      key: options.key,
    };
  }

  try {
    const transporter =
      options.smtpTransporter ??
      nodemailer.createTransport({
        auth: {
          pass: smtpPassword,
          user: smtpUser,
        },
        host: smtpHost,
        port: smtpPort,
        secure: smtpSecure,
      });

    await transporter.sendMail({
      from: options.from,
      html: htmlBody(alert, review),
      subject: subjectForReview(review),
      text: textBody(alert, review),
      to: options.to,
    });

    markSent(options.key, options.nowMs);

    return {
      state: "sent",
      reason: `E-post sendt til ${options.to} via SMTP.`,
      key: options.key,
    };
  } catch (error) {
    return {
      state: "error",
      reason: errorMessage(error),
      key: options.key,
    };
  }
}

async function sendWithResend(
  alert: NodvarselAlert,
  review: AlertReview,
  options: {
    apiKey?: string;
    fetcher?: FetchLike;
    from?: string;
    key: string;
    nowMs: number;
    to: string;
  },
): Promise<NotificationResult> {
  if (!options.apiKey) {
    return {
      state: "skipped",
      reason: "RESEND_API_KEY mangler, e-post ble ikke sendt.",
      key: options.key,
    };
  }

  if (!options.from) {
    return {
      state: "skipped",
      reason: "ALERT_EMAIL_FROM mangler, e-post ble ikke sendt.",
      key: options.key,
    };
  }

  try {
    const response = await (options.fetcher ?? fetch)(RESEND_EMAILS_URL, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${options.apiKey}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        from: options.from,
        to: options.to,
        subject: subjectForReview(review),
        text: textBody(alert, review),
        html: htmlBody(alert, review),
      }),
    });

    if (!response.ok) {
      throw new Error(`Resend svarte med HTTP ${response.status}`);
    }

    markSent(options.key, options.nowMs);

    return {
      state: "sent",
      reason: `E-post sendt til ${options.to}.`,
      key: options.key,
    };
  } catch (error) {
    return {
      state: "error",
      reason: errorMessage(error),
      key: options.key,
    };
  }
}

function subjectForReview(review: AlertReview): string {
  if (review.classification === "confirmed_yes") {
    return "erdetkriginorge.no har satt JA";
  }

  return "erdetkriginorge.no trenger manuell vurdering";
}

function textBody(alert: NodvarselAlert, review: AlertReview): string {
  return [
    subjectForReview(review),
    "",
    `AI-klassifisering: ${review.classification}`,
    `Tillit: ${review.confidence}`,
    `Gjelder Norge naa: ${review.appliesToNorwayNow ? "ja" : "nei"}`,
    `Eksplisitt krig/vaepnet angrep: ${
      review.explicitWarOrArmedAttack ? "ja" : "nei"
    }`,
    `Test/oevelse: ${review.isTestOrExercise ? "ja" : "nei"}`,
    `Modell: ${review.model}`,
    `Sjekket: ${review.checkedAt}`,
    "",
    `AI-begrunnelse: ${review.reason}`,
    review.error ? `Feil: ${review.error}` : "",
    "",
    "Varsel:",
    `Tittel: ${alert.title || "(tom)"}`,
    `Beskrivelse: ${alert.description || "(tom)"}`,
    `Lenke: ${alert.link || "(tom)"}`,
    `Publisert: ${alert.publishedAt || "(ukjent)"}`,
  ]
    .filter(Boolean)
    .join("\n");
}

function htmlBody(alert: NodvarselAlert, review: AlertReview): string {
  return `<h1>${escapeHtml(subjectForReview(review))}</h1>
<p><strong>AI-klassifisering:</strong> ${escapeHtml(review.classification)}</p>
<p><strong>Tillit:</strong> ${escapeHtml(review.confidence)}</p>
<p><strong>Gjelder Norge naa:</strong> ${
    review.appliesToNorwayNow ? "ja" : "nei"
  }</p>
<p><strong>Eksplisitt krig/vaepnet angrep:</strong> ${
    review.explicitWarOrArmedAttack ? "ja" : "nei"
  }</p>
<p><strong>Test/oevelse:</strong> ${
    review.isTestOrExercise ? "ja" : "nei"
  }</p>
<p><strong>Modell:</strong> ${escapeHtml(review.model)}</p>
<p><strong>Sjekket:</strong> ${escapeHtml(review.checkedAt)}</p>
<p><strong>AI-begrunnelse:</strong> ${escapeHtml(review.reason)}</p>
${review.error ? `<p><strong>Feil:</strong> ${escapeHtml(review.error)}</p>` : ""}
<h2>Varsel</h2>
<p><strong>Tittel:</strong> ${escapeHtml(alert.title || "(tom)")}</p>
<p><strong>Beskrivelse:</strong> ${escapeHtml(alert.description || "(tom)")}</p>
<p><strong>Lenke:</strong> ${
    alert.link ? `<a href="${escapeHtml(alert.link)}">${escapeHtml(alert.link)}</a>` : "(tom)"
  }</p>
<p><strong>Publisert:</strong> ${escapeHtml(alert.publishedAt || "(ukjent)")}</p>`;
}

function shouldUseSmtp(options: SendAlertNotificationOptions): boolean {
  return Boolean(
    options.smtpTransporter ||
      options.smtpHost ||
      options.smtpPassword ||
      options.smtpPort !== undefined ||
      options.smtpSecure !== undefined ||
      options.smtpUser ||
      process.env.SMTP_HOST ||
      process.env.SMTP_PASSWORD ||
      process.env.SMTP_PORT ||
      process.env.SMTP_SECURE ||
      process.env.SMTP_USER,
  );
}

function parseOptionalInteger(value: string | undefined): number | undefined {
  if (!value) {
    return undefined;
  }

  const parsed = Number.parseInt(value, 10);

  return Number.isFinite(parsed) ? parsed : undefined;
}

function parseOptionalBoolean(value: string | undefined): boolean | undefined {
  if (!value) {
    return undefined;
  }

  return ["1", "true", "yes", "ja"].includes(value.toLocaleLowerCase("nb-NO"));
}

function createNotificationKey(
  alert: NodvarselAlert,
  review: AlertReview,
): string {
  return createHash("sha256")
    .update(
      JSON.stringify({
        classification: review.classification,
        title: alert.title,
        description: alert.description,
        link: alert.link,
        publishedAt: alert.publishedAt,
      }),
    )
    .digest("hex");
}

function isRecentlySent(key: string, nowMs: number): boolean {
  pruneOldKeys(nowMs);
  const sentAt = sentNotificationKeys.get(key);

  return sentAt !== undefined && nowMs - sentAt < DEDUPE_TTL_MS;
}

function markSent(key: string, nowMs: number): void {
  pruneOldKeys(nowMs);
  sentNotificationKeys.set(key, nowMs);
}

function pruneOldKeys(nowMs: number): void {
  for (const [key, sentAt] of sentNotificationKeys.entries()) {
    if (nowMs - sentAt >= DEDUPE_TTL_MS) {
      sentNotificationKeys.delete(key);
    }
  }
}

function escapeHtml(value: string): string {
  return value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}
