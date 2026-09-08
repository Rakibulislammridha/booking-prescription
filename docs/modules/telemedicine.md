# Telemedicine (Module K) — decisions and contracts

Owner: engineer T. Companion to BRIEF §5.K (+ §5.C/§5.G/§5.I/§5.J/§5.M), SCHEMA §3.8, ARCHITECTURE §5.4/§6.
Nothing here re-specifies those documents; it records the decisions this module had to take.

## 1. The rule everything else follows

> "Ends in the **same prescription flow** as an in-person visit — no separate prescription path." (BRIEF §5.K)

So the module owns exactly three things — a room, a call, and a scoped credential — and composes everything
else. It writes no visit, no prescription, no serial transition, no queue document and no message of its own.

```
BOOKING            BookAppointment(BookingChannel::Telemedicine)      ← Booking module, unchanged
  ↓ AppointmentBooked
ROOM               ScheduleRoom → telemedicine_rooms (status scheduled)
  ↓ TelemedicineInviteIssued → QueueNotification(telemedicine_invite) ← Notifications module's own action
CALL START         StartCall
     ├─ CallSerial ..................... Serials       → serial in_consultation, queue updates, ETA moves
     │    └─ SerialCalled → StartVisitOnSerialCalled .. Prescription  → the ORDINARY visits row
     ├─ StartConsultation .............. Serials       → consultation_started_at
     └─ provider->createRoom() + telemedicine_sessions row
PRESCRIBING        Prescription/Writer, unchanged, opened on that visit
  ↓ PrescriptionIssued → CompleteConsultationOnPrescriptionIssued ... Serials → completed
CALL END           EndCall → CompleteConsultation (idempotent) + duration + usage_counters.telemedicine_minutes
```

`Telemedicine/Console` renders the **untouched** `Prescription/Writer` component beside a video rail, with the
props the Prescription module's own `WriterPayloadBuilder` produced. Nothing under
`resources/js/panel/Pages/Prescription/**` or `app/Domain/Prescription/**` was edited.

## 2. The provider contract

`App\Domain\Telemedicine\Contracts\VideoProvider` — five verbs: `createRoom`, `mintToken`, `revoke`,
`closeRoom`, `verifyWebhook`. Drivers translate an already-authorised `TokenRequest`; they never decide who may
join (that is the plan gate, the guard, the signature and the room status, in that order).

| Driver | Tokens | Rooms | Webhooks |
|---|---|---|---|
| `LiveKitProvider` | HS256 JWT, `video` grant object | Twirp REST (`/twirp/livekit.RoomService/*`) | `Authorization` JWT whose `sha256` claim is the base64 digest of the raw body |
| `JitsiProvider` | HS256 JWT, `context.user.moderator` | none — a Jitsi room exists when someone joins | none (self-hosted Jitsi has no first-party webhook); the client beacons stand in |
| `NullVideoProvider` | HS256 JWT signed with `APP_KEY`, same claims and TTL | logged | none |

**Grants are a function of the role and nothing else** (`TokenRequest::forRole`): both roles publish and
subscribe; only the doctor gets `roomAdmin`/`roomRecord`, and only when the clinic enabled recording. There is no
request shape in which a patient asks for a doctor's token.

`VideoProviderManager` is bound **non-shared**: it memoises one clinic's credentials, so it must not outlive the
request under Octane. A driver whose credentials are incomplete is replaced by the null driver rather than being
called and failing — which is also why the suite never reaches a real service.

Agora is named by BRIEF §5.K but is **not implemented**: its token format is a binary packing rather than a JWT,
and LiveKit + Jitsi already cover the paid and the self-hosted case. The enum keeps the value.

## 3. Identity, links and tokens

* `telemedicine_rooms.room_name` = `t{tenantId}-{ulid}` is the row's public handle (SCHEMA §3.8 has no
  `public_id` here). The route pattern rejects anything else before a query runs.
* The patient's SMS carries a **relatively** signed, expiring URL (`/telemedicine/j/{room}`). Relative, because a
  clinic may move from `slug.bp.app` to its own domain and an absolute signature would break the links already
  sent. A valid signature logs the patient in on the `patient` guard — the same trust model as the portal's OTP,
  with the code baked into the link, because a patient fighting an OTP form thirty seconds before a consultation
  is a patient who misses it.
* The **media** credential never travels by SMS: it is minted per join with a 15-minute TTL
  (`config('telemedicine.token_ttl')`) and never stored (SCHEMA §3.8).

## 4. Waiting room

The queue half of the patient's screen is the Queue module's own `QueueState` — embedded on first paint,
then kept live by `useQueueState()` over the existing public channel with the existing 5-second ETag poll. A
patient at home sees the identical "now serving / N ahead / estimated time" a patient in the corridor sees. This
module adds only what the corridor has no equivalent of: is the room open, is the doctor in it, may I ask for a
token yet (`GET /telemedicine/room/{room}/state`).

## 5. Deviations and cross-module changes

1. **A thirteenth `NotificationEvent`.** `telemedicine_invite` was added to the Notifications enum, to both
   `event_key` CHECKs (migration `2026_02_10_000300`, under this module's prefix — the 2026_02_08 files are on
   `main` and are never edited) and to `notifications.defaults.*`. Every other event names a place and a time;
   a video consultation has no place — the link *is* the appointment — and `booking_confirmed`'s body correctly
   has no `{{link}}`. Two Notifications assertions that pin the catalogue size were updated with it.
2. **Six `telemedicine.*` settings keys** (SCHEMA Appendix B). `telemedicine.api_secret` holds a Laravel-encrypted
   string written by `TelemedicineSettings::storeSecret()`, because `settings.value` is plain jsonb.
   **Open [foundation] need:** the generic clinic-settings screen renders it as an ordinary text field, so an
   admin editing it there would store a plaintext secret (the reader tolerates both). The registry needs a
   `secret: true` flag that masks the field and encrypts on write.
3. **No new permissions.** The enum is foundation-owned and this module needs none: `prescriptions.write` plus
   "is this your patient?" gates consulting, `prescriptions.view.any` / an operator's `queue.call-next` gates
   watching. `TelemedicineRoomPolicy::view` reuses `ChannelGuards::doctor`'s rule verbatim — the call-next
   permission runs the queue, it does not open a colleague's chamber.
4. **The site plan gate is this module's own middleware**, not the shared `plan:` alias: `plan:` redirects a
   refusal to the staff subscription page, which is right for a clinic manager and wrong for a patient holding an
   SMS link. `EnsureTelemedicineEnabled` answers 402 with a page that explains the clinic has no video service.
   Panel and API routes carry `plan:telemedicine`.
5. **`RequirePatientSession` instead of `auth:patient`** on the room routes, because the global
   `redirectGuestsTo` sends anything that is not `site.portal.*` to the STAFF login.
6. **The panel's video widgets are MUI siblings of the site's Tailwind ones.** The panel has no Tailwind, so the
   site components would render unstyled there. What the surfaces share is the part that must not diverge — the
   call machine, the pre-flight and the video client in `@site/Pages/Telemedicine/core/**`, which carry no
   styling and are covered by one Vitest suite.
7. **The LiveKit browser SDK is not wired.** The server driver is complete; the client half needs the
   `livekit-client` npm package and `package.json` is foundation-owned. Until it lands, a LiveKit-configured
   clinic degrades to local preview — the same fallback a failed SDK download takes.
8. **Issuing navigates away from the console** to the ordinary prescription page (the ordinary post-issue
   behaviour). The serial is already completed by then; the doctor steps back into the console to hang up, and
   `EndCall` is idempotent on an already-completed serial.
