import { describe, expect, it } from "vitest";
import type { AlertReview } from "@/lib/ai-classifier";
import {
  sendAlertNotification,
  shouldNotifyForReview,
} from "@/lib/notifications";
import type { NodvarselAlert } from "@/lib/status";

const alert: NodvarselAlert = {
  title: "Nødvarsel: Væpnet angrep mot Norge",
  description: "Søk dekning.",
  link: "https://www.nodvarsel.no/varsler/test",
  publishedAt: "Wed, 08 Jul 2026 10:00:00 GMT",
};

const review: AlertReview = {
  classification: "confirmed_yes",
  confidence: "high",
  appliesToNorwayNow: true,
  explicitWarOrArmedAttack: true,
  isTestOrExercise: false,
  reason: "Bekreftet i test.",
  model: "test-model",
  checkedAt: "2026-07-08T10:00:00.000Z",
};

describe("alert notifications", () => {
  it("sends email with Resend when configured", async () => {
    const requestBodies: Array<Record<string, unknown>> = [];

    const result = await sendAlertNotification(alert, review, {
      apiKey: "resend-key",
      from: "Varsel <status@example.com>",
      to: "lyder2@mac.com",
      dedupe: false,
      fetcher: async (_input, init) => {
        requestBodies.push(
          JSON.parse(String(init?.body)) as Record<string, unknown>,
        );

        return new Response(JSON.stringify({ id: "email-id" }), { status: 200 });
      },
    });

    expect(result.state).toBe("sent");
    expect(requestBodies[0]?.to).toBe("lyder2@mac.com");
    expect(requestBodies[0]?.subject).toBe("erdetkriginorge.no har satt JA");
  });

  it("sends email with SMTP when configured", async () => {
    const messages: Array<Record<string, unknown>> = [];

    const result = await sendAlertNotification(alert, review, {
      dedupe: false,
      from: "lyder@lyder.no",
      smtpPassword: "smtp-password",
      smtpTransporter: {
        sendMail: async (message) => {
          messages.push(message as Record<string, unknown>);
        },
      },
      smtpUser: "lyder@lyder.no",
      to: "lyder2@mac.com",
    });

    expect(result.state).toBe("sent");
    expect(result.reason).toContain("SMTP");
    expect(messages[0]?.from).toBe("lyder@lyder.no");
    expect(messages[0]?.to).toBe("lyder2@mac.com");
    expect(messages[0]?.subject).toBe("erdetkriginorge.no har satt JA");
  });

  it("skips SMTP email when password is missing", async () => {
    const result = await sendAlertNotification(alert, review, {
      dedupe: false,
      from: "lyder@lyder.no",
      smtpPassword: "",
      smtpUser: "lyder@lyder.no",
      to: "lyder2@mac.com",
    });

    expect(result.state).toBe("skipped");
    expect(result.reason).toContain("SMTP_PASSWORD");
  });

  it("skips email when Resend is not configured", async () => {
    const result = await sendAlertNotification(alert, review, {
      apiKey: "",
      from: "Varsel <status@example.com>",
      dedupe: false,
    });

    expect(result.state).toBe("skipped");
    expect(result.reason).toContain("RESEND_API_KEY");
  });

  it("notifies only confirmed yes and uncertain reviews", () => {
    expect(shouldNotifyForReview(review)).toBe(true);
    expect(
      shouldNotifyForReview({
        ...review,
        classification: "uncertain",
      }),
    ).toBe(true);
    expect(
      shouldNotifyForReview({
        ...review,
        classification: "no",
      }),
    ).toBe(false);
  });
});
