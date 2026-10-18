# LUMUI Viewer specification and product design

Viewer specification: 0.1  
Compatible LUMUI document: 1.0  
Compatible web protocol: 0.6  
Status: design baseline  
Date: 30 July 2026

## Abstract

A LUMUI viewer is a trusted application that discovers, validates and presents
LUMUI publishers. It may be a native browser, a web application, an embedded
control, an operating-system surface, an assistive client or a server-rendered
HTML experience.

This document defines how to make that viewer understandable for ordinary
people, useful for developers and dependable for organizations. It specifies
the viewer architecture, user controls, rendering pipeline, accessibility
behavior, security boundaries, implementation quality, testing and release
criteria.

The central product promise is simple:

> A person opens one normal address. The viewer chooses a good default
> presentation. The person may then adjust device simulation, layout, text,
> contrast, motion, interaction and output without changing the application.

## 1. Relationship to the LUMUI specification

The core LUMUI specification defines publisher documents, actions, discovery,
render context and renderer requirements. This viewer specification specializes
those requirements for complete viewer products.

It does not change application meaning or add fields to a LUMUI surface.
Viewer settings are host-owned context. They are not publisher styling
instructions and do not grant capabilities.

`MUST`, `MUST NOT`, `SHOULD`, `SHOULD NOT` and `MAY` have the meanings defined
by the core LUMUI specification.

Where this document and the core specification conflict, the core
specification is authoritative for protocol behavior. This document is
authoritative for a claimed LUMUI Viewer conformance class.

## 2. Goals

A conforming viewer is designed to achieve all of the following:

1. Open a normal HTTP or HTTPS address without requiring a semantic endpoint
   to be copied separately.
2. Discover and validate LUMUI representations before rendering them.
3. Provide a useful automatic view without making the person configure the
   renderer first.
4. Keep content, profile, appearance, output, interaction and accessibility
   preferences independent.
5. Preserve application meaning and action identity across every supported
   presentation.
6. Make accessibility controls easy to find, combine and reset.
7. Keep address, navigation, permissions, identity and dangerous confirmation
   inside trusted viewer chrome.
8. Offer meaningful fallback when a component, media type or capability is not
   supported.
9. Support implementations in any language or framework that can honor the
   wire contracts and behavior.
10. Make validation and rendering decisions inspectable without exposing
    secret application data.
11. Remain maintainable through small responsibilities, explicit interfaces,
    dependency inversion and one declared object per source file.
12. Provide testable conformance claims rather than a vague compatibility
    label.

## 3. Non-goals

The viewer is not:

- a mechanism for publishers to control operating-system chrome;
- a way to bypass browser origin, cookie, permission or download policy;
- an HTML compatibility claim when no HTML renderer is installed;
- a promise that every physical device behaves like a simulated viewport;
- a general-purpose script runtime for publisher code;
- a source of application business rules;
- a replacement for publisher authentication and authorization;
- a visual theme dictated by the publisher;
- proof of accessibility based only on a screenshot;
- a requirement to use the reference PHP, JavaScript, C# or Avalonia modules.

## 4. Product principles

### 4.1 Good default before configuration

The first rendering MUST be useful. A person does not need to understand render
profiles or output channels before reading a page or completing a common task.

The default viewer selection is `Auto`. Auto derives a standard profile from
the actual host, uses visual output when a visual display is active, follows
platform accessibility preferences and selects the viewer's default accessible
appearance.

### 4.2 Meaning before presentation

The publisher owns content, state, validation and actions. The viewer owns
geometry, controls, focus, reading order, typography, density and host chrome.

### 4.3 User settings over publisher preference

A publisher MAY describe content priority and semantic relationships. It MUST
NOT override a person's text, contrast, motion, output or interaction
requirements.

### 4.4 Safe, reversible control

Every viewer customization MUST be reversible. The View panel MUST provide a
clear `Reset this site` operation and SHOULD provide `Reset all viewer
settings`.

### 4.5 Progressive disclosure

Common controls remain close to the content. Specialist simulation and
diagnostic controls remain available without dominating the normal browsing
experience.

### 4.6 Equivalent capability

A different presentation may change composition and pacing. It MUST preserve
required content, validation, navigation and enabled actions unless host policy
explicitly blocks them.

### 4.7 Trust is visible

Publisher identity appears inside the rendered surface. Viewer identity,
address, permissions, security warnings and dangerous confirmations appear in
trusted chrome that publisher content cannot imitate.

## 5. Audiences and primary scenarios

### 5.1 People using applications

A person wants to open an address, understand what the application offers and
complete a task. The default view should be familiar, responsive and quiet.
Accessibility choices should not require developer terminology.

### 5.2 People with access needs

A person may need larger text, stronger contrast, reduced motion, reduced
transparency, larger targets, lower information density, guided pacing,
keyboard-only operation, voice input, switch input or non-visual output.
Requirements may be combined.

### 5.3 Developers

A developer wants to inspect the received document, validation results,
semantic tree, action messages, request trace, selected context and fallback
decisions.

### 5.4 Designers and testers

A designer or tester wants to compare the same semantic surface across profiles
and preferences without confusing simulation with physical-device
certification.

### 5.5 Organizations and administrators

An organization wants policy, deployment, update, authentication, certificate,
logging and extension controls. Administrative policy may constrain unsafe
choices but MUST NOT silently weaken accessibility.

### 5.6 Embedded products

A kiosk, appliance, vehicle surface or dedicated device wants a restricted
viewer with a fixed host profile, allowlisted origins and a small set of
host-owned controls.

## 6. Conformance classes

A compatibility statement names the viewer version, LUMUI document version,
classes, profiles, component families and extensions.

### 6.1 Viewer Core

Viewer Core includes:

- address loading and discovery;
- descriptor and surface validation;
- bounded navigation and redirect handling;
- Renderer Core component coverage;
- trusted address and navigation chrome;
- default automatic rendering;
- keyboard and focus behavior;
- text reflow, contrast and reduced motion;
- semantic errors and readable unsupported-component fallback;
- action dispatch and state reconciliation;
- explicit capability reporting.

### 6.2 Viewer Web Integration

Viewer Web Integration adds:

- complete server-rendered HTML fallback; or
- a client-side HTML integration backed by a conforming HTML renderer;
- route-level content negotiation;
- document metadata;
- ordinary browser history and deep linking;
- progressive enhancement without loss of fallback functionality.

### 6.3 Viewer Native

Viewer Native adds:

- native toolkit controls;
- a native accessibility tree;
- platform input and focus integration;
- platform-mediated resources, downloads and capabilities;
- packaged renderer resources without an embedded HTML engine.

### 6.4 Viewer Accessibility Advanced

Viewer Accessibility Advanced adds:

- independently composable accessibility preferences;
- screen-reader-first output inspection;
- guided interaction;
- platform preference synchronization;
- persistent global and per-origin overrides;
- keyboard, voice and switch verification for claimed platforms;
- accessible settings and reset flows.

### 6.5 Viewer Developer Tools

Viewer Developer Tools adds:

- exact and formatted source;
- validation diagnostics with source locations;
- semantic tree inspection;
- request and redirect trace;
- action request and result inspection with secret redaction;
- effective render-context inspection;
- component support and fallback reports;
- exportable diagnostic bundles with privacy review.

## 7. System context

The viewer is divided into trusted host services and replaceable adapters.

```text
Person
  |
  v
Trusted viewer chrome
  |
  +--> Navigation controller --> Address loader --> HTTP transport
  |                                  |
  |                                  +--> Discovery
  |                                  +--> Authentication broker
  |                                  +--> Cache
  |
  +--> Document pipeline --> Parser --> Schema validator --> Policy validator
  |                                                   |
  |                                                   v
  |                                            Validated model
  |
  +--> Context resolver --> Renderer selector --> Renderer --> Platform UI
  |        |                                          |
  |        +--> profile                               +--> accessibility tree
  |        +--> appearance                            +--> focus
  |        +--> output                                +--> media/capabilities
  |        +--> interaction
  |        +--> accessibility
  |
  +--> Action coordinator --> Confirmation --> HTTP transport --> Reconcile
  |
  +--> Diagnostics, policy, settings and secure storage
```

Publisher input remains untrusted until parsing, schema validation and host
policy validation complete. Renderers receive a validated model, not arbitrary
JSON.

## 8. Architectural responsibilities

### 8.1 Navigation controller

The navigation controller owns logical addresses, current destination, back,
forward, reload, cancellation, history replacement and navigation events. It
does not parse LUMUI documents.

### 8.2 Address loader

The address loader implements the core discovery algorithm. It applies
transport limits, redirects, media-type checks, origin rules and cancellation.
It returns typed load results.

### 8.3 Document parser

The parser converts bytes into a syntax model with bounded depth, size and
number ranges. Parsing success does not imply protocol validity.

### 8.4 Schema validator

The schema validator applies the complete versioned schemas, rejects unknown
fields and returns structured diagnostics. It does not make network requests.

### 8.5 Policy validator

The policy validator checks origins, URLs, sensitivity, capability declarations,
resource limits, extension policy and renderer compatibility.

### 8.6 Context resolver

The context resolver combines host facts, administrative policy, platform
preferences, global user settings, per-origin settings and session overrides.
It produces one immutable effective render context.

### 8.7 Renderer selector

The selector chooses a compatible renderer pack from explicit capability
manifests. It never guesses support from class names or installed files.

### 8.8 Renderer

The renderer maps validated semantic nodes to platform output. It owns
composition, controls, presentation, focus and assistive semantics. It does not
perform business operations.

### 8.9 Action coordinator

The action coordinator validates action availability, gathers bounded input,
obtains trusted confirmation, sends the message once, handles challenges and
reconciles the result.

### 8.10 Capability broker

The capability broker mediates files, camera, microphone, location, contacts,
payments, notifications and other privileged operations. A renderer cannot
grant these capabilities.

### 8.11 Settings service

The settings service stores viewer-owned choices. It separates global,
per-origin, session and preview settings and can reset each scope.

### 8.12 Diagnostics service

Diagnostics observes typed events from the other services. It does not become a
service locator and does not receive secret values unless an explicit redaction
policy allows them.

## 9. Framework and language independence

LUMUI is a wire and behavior contract, not a framework API. A publisher or
viewer may be implemented in any environment that can:

- serve or request HTTP;
- encode and decode JSON without losing required value distinctions;
- validate the published schemas and registered component vocabulary;
- preserve document and action identifiers;
- implement revisions, errors, caching and origin rules;
- provide an appropriate renderer or HTML integration;
- pass the claimed conformance tests.

The same valid publisher can therefore be written with ASP.NET, PHP, Java,
Kotlin, Go, Rust, C, C++, Julia, Python, Ruby, JavaScript, TypeScript or another
language.

| Environment | Natural publisher shape | Browser delivery |
|---|---|---|
| ASP.NET Core | Endpoint, application service and immutable DTO mapper | Invoke or adapt `lumui-html-renderer`, or use a conforming .NET HTML renderer |
| PHP | Surface provider, validator, action handler and web publisher | Use the reference `lumui-html-renderer` directly |
| C or C++ | HTTP adapter, generated schema/domain types and explicit ownership | Bind a renderer library, call a renderer service or return LUMUI to a viewer |
| Julia | HTTP route, typed domain mapping and schema validation package | Call a renderer service or provide a Julia renderer adapter |
| Java/Kotlin | Controller, use-case service and records/data classes | Use a JVM renderer or renderer service |
| Go/Rust | Thin HTTP handler over typed domain services | Use an in-process implementation, sidecar or semantic viewer |
| Browser JavaScript | Route/API client and validated client model | Use a conforming browser-side renderer with normal HTML fallback |

Language choice does not change the published media types, schema rules,
component meaning or action behavior.

## 10. Recommended implementation strategies

The following order gives teams the simplest path to a dependable result.

### 10.1 Strategy A: semantic publisher plus reference HTML renderer

This is the recommended default for an ordinary website.

1. Domain and application services produce current business state.
2. A surface mapper converts that state into a LUMUI document.
3. The document is validated.
4. A LUMUI request receives `application/lumui+json`.
5. A normal browser request passes the same document to
   `lumui-html-renderer`.
6. The renderer returns complete HTML.
7. Optional host JavaScript enhances actions and view controls.

Advantages:

- one semantic source;
- complete no-script HTML;
- excellent search, link and assistive-technology behavior;
- immediate compatibility with ordinary browsers;
- LUMUI clients receive the same application meaning;
- low initial implementation cost.

### 10.2 Strategy B: native or toolkit viewer

Use this for a dedicated browser, desktop client, kiosk or operating-system
surface. The viewer requests LUMUI directly and maps components to native
controls. It does not need an HTML engine.

Advantages:

- platform controls and accessibility;
- strong host capability mediation;
- offline and installed-app integration;
- consistent trusted chrome;
- no publisher script execution.

### 10.3 Strategy C: renderer adapter for another server framework

Create a renderer behind the target framework's response abstraction. The
adapter translates framework requests and responses; it MUST NOT redefine the
LUMUI model.

This is appropriate when an organization wants a pure ASP.NET, JVM, Go, Rust
or other deployment while keeping protocol compatibility.

### 10.4 Strategy D: renderer sidecar or internal service

A non-PHP publisher may call a separately deployed HTML renderer over an
authenticated internal interface. The interface accepts only a validated
surface and bounded render context and returns HTML plus metadata.

Use this when in-process language binding is impractical. The service:

- MUST be isolated from the public network unless intentionally public;
- MUST authenticate callers;
- MUST apply size and time limits;
- MUST version its renderer contract;
- MUST escape all publisher data;
- SHOULD be deployed close to the publisher;
- SHOULD cache only public, non-user-specific results.

### 10.5 Strategy E: compatibility gateway

A gateway may map an existing API or application model into LUMUI. This is
useful for adoption, but the gateway becomes responsible for correct semantics,
authorization, revisions, actions and errors. Screen scraping is not a
conforming semantic mapping.

## 11. Browser HTML integration

The easiest conforming browser experience is a complete server-rendered page
created from the validated LUMUI surface by `lumui-html-renderer`.

The HTML integration consists of:

- semantic HTML output;
- document head metadata;
- viewer-host stylesheet and behavior assets;
- action enhancement;
- a default responsive profile;
- an optional host-owned View panel;
- discovery links back to the LUMUI representation and descriptor.

The integrated viewer app SHOULD initialize from the server-rendered document,
not replace it with a loading shell. Enhancement may then:

- submit declared actions without a full page transition;
- reconcile returned surfaces;
- preserve focus and scroll;
- change viewer context;
- expose diagnostics when authorized.

If enhancement fails, links, forms, content and server action handling remain
usable.

## 12. Viewer information architecture

The viewer separates trusted controls into four layers.

### 12.1 Essential navigation

Always available when applicable:

- Back;
- Forward;
- Reload or Stop;
- Address;
- Open;
- page or publisher identity;
- security and permission status.

### 12.2 Quick View control

A single clearly named `View` control opens the common settings. It MUST be
keyboard reachable and expose its expanded state to assistive technology.

The first level contains:

- Text size;
- Contrast;
- Motion;
- Layout comfort;
- Interaction mode;
- Output;
- Reset this site.

The labels are user-facing. Protocol identifiers may appear in developer
details, not as the primary labels.

### 12.3 More view options

The second level contains:

- device profile and simulation;
- appearance;
- transparency;
- target size;
- input simulation;
- per-origin persistence;
- global defaults.

### 12.4 Developer tools

Developer tools are separate from View settings. Opening diagnostics does not
change application content or grant publisher capabilities.

## 13. Default view behavior

On first use, the viewer:

1. determines actual platform display and input capabilities;
2. reads platform accessibility preferences;
3. applies mandatory administrative policy;
4. applies global viewer preferences;
5. applies allowed per-origin preferences;
6. selects the closest standard profile;
7. selects visual or platform assistive output;
8. selects the default accessible appearance;
9. renders the requested page;
10. announces the page title and any important loading result.

The default MUST NOT be a simulated phone, watch or kiosk merely because those
profiles are available. Simulation is an explicit preview action.

Auto selection SHOULD remain stable while a task is in progress. A resize may
reflow the active profile without changing semantic state.

## 14. Effective setting precedence

From strongest to weakest:

1. safety and legal platform constraints;
2. active assistive-technology requirements;
3. explicit session override made by the person;
4. saved per-origin preference;
5. saved global viewer preference;
6. platform preference;
7. automatic viewer default;
8. publisher hint where the protocol permits a hint.

A stronger setting may constrain a weaker setting but SHOULD preserve the
person's intent. For example, a high-contrast platform policy may replace an
incompatible appearance while retaining the chosen text size and interaction
mode.

The viewer SHOULD explain why an unavailable choice is disabled.

## 15. Settings model

The viewer keeps independent axes instead of one large theme choice.

| Axis | Examples | Effect |
|---|---|---|
| Profile | Auto, web, desktop, tablet, phone, watch, kiosk, appliance | Composition and device assumptions |
| Appearance | Default, platform, installed renderer appearances | Visual language |
| Text | Default, larger steps, explicit scale | Typography and reflow |
| Contrast | System, standard, high | Palette and boundaries |
| Motion | System, full, reduced, none when supported | Animation and transitions |
| Transparency | System, standard, reduced, opaque | Material effects |
| Density | Comfortable, compact, spacious | Information and spacing density |
| Targets | System, standard, large | Interactive target size |
| Interaction | Standard, guided | Pacing and disclosure |
| Output | Visual, screen-reader-first | Presentation channel |
| Input simulation | None or an explicit test mode | Developer/test behavior |

An implementation MAY present presets such as `Reading`, `Low motion` or
`Guided`. Applying a preset updates named axes and displays the resulting
choices. Presets MUST NOT hide which settings changed.

## 16. Accessibility preferences are composable

Large text, high contrast and reduced motion are not mutually exclusive. A
Viewer Accessibility Advanced implementation MUST model them as independent
effective requirements even if a compact UI initially offers presets.

The viewer MUST support:

- text zoom without horizontal page scrolling at normal reading widths;
- content reflow without loss of action or validation;
- high contrast without loss of publisher identity;
- state indicators that do not depend only on color;
- reduced motion without delayed access to content;
- reduced transparency where transparency affects legibility;
- visible keyboard focus;
- deterministic focus and reading order;
- escape from modal and guided contexts;
- accessible names, descriptions, values, errors and state;
- persistence that does not expose disability information to publishers.

The viewer MUST NOT send accessibility settings to a publisher unless a
protocol field explicitly requires a content adaptation and the person or
policy permits disclosure.

## 17. Text control

Text size is one of the most frequently needed controls and SHOULD be available
at the first View level.

Requirements:

- offer at least default, larger and largest useful steps;
- display the current value;
- apply without navigation;
- preserve focused component and input;
- reflow labels, tables, dialogs and navigation;
- avoid clipping and overlapping;
- allow platform text scaling to remain effective;
- persist according to selected scope;
- provide a direct reset.

Renderer spacing and target sizes SHOULD grow enough to keep enlarged text
usable. Scaling glyphs alone is insufficient.

## 18. Contrast, color and transparency

High contrast is a complete appearance constraint. It covers text, icons,
focus, disabled controls, validation, charts and selection.

The viewer:

- MUST calculate or verify required foreground/background relationships;
- MUST replace incompatible appearance tokens;
- MUST retain visible component boundaries;
- MUST preserve identity without using identity color as the only signal;
- MUST reduce or remove translucent layers when they reduce readability;
- SHOULD follow platform forced-color behavior where available;
- MUST test hover, focus, active, disabled, error and selected states.

## 19. Motion

Reduced motion applies to:

- navigation transitions;
- expanding and collapsing regions;
- progress and loading decoration;
- background effects;
- parallax;
- auto-advancing content;
- animated focus or selection;
- device-frame transitions.

Essential state changes remain immediate and understandable. Motion reduction
does not suppress progress state, completion or error feedback.

## 20. Standard and guided interaction

Standard mode presents the renderer's normal information structure.

Guided mode:

- presents one meaningful decision group or task step at a time;
- keeps progress and destination clear;
- uses plain action labels;
- preserves a way back;
- retains validation near the responsible input;
- confirms destructive outcomes;
- returns focus predictably after each step;
- does not remove required choices;
- does not change publisher action identifiers;
- does not claim that the application itself has a different state.

The View control uses the label `Interaction` or `Task mode`, with options
`Standard` and `Guided`. It MUST NOT label this control as a device.

## 21. Output

Output is independent from profile and interaction.

Visual output maps components to visible controls and a platform accessibility
tree.

Screen-reader-first output exposes a stable linear representation useful for
non-visual operation, testing and inspection. A native platform may satisfy
this through its accessibility tree without drawing a duplicate transcript.

Changing output:

- preserves the logical destination;
- preserves surface revision and current values;
- preserves enabled actions;
- reconciles focus by semantic ID;
- does not cause an action request;
- is announced through trusted host feedback.

Future output extensions, such as voice-first or tactile rendering, require an
explicit renderer capability and fallback.

## 22. Device profiles and simulation

A profile represents composition and host assumptions, not only viewport size.

The viewer supports claimed profiles with:

- suitable navigation;
- appropriate information density;
- target sizes for the active input;
- safe content area;
- meaningful disclosure;
- focus and reading order;
- a profile-specific fallback plan.

Simulation is clearly labeled `Preview device` or `Simulate profile`.
Simulation MUST NOT imply physical-device certification.

The default profile control value is `Auto`. When a person selects a simulated
profile, the viewer SHOULD offer `Return to my device`.

## 23. Responsive composition

Renderer decisions are based on semantic relationships and available space.

Desktop and web layouts may use parallel regions, navigation rails and wider
data views. Phone layouts may use one primary column, bottom navigation and
progressive disclosure. Watch layouts prioritize immediate status and actions.
Kiosk layouts provide large public choices and suppress sensitive content.

Across compositions:

- component IDs remain stable;
- DOM or native visual order must not corrupt semantic reading order;
- current input survives compatible reflow;
- validation remains attached;
- deferred information remains reachable;
- navigation does not unexpectedly change destination.

## 24. Appearance

An appearance is a renderer-owned visual system. It defines palette roles,
typography, spacing, shapes, icons, depth and permitted motion.

The appearance picker:

- previews choices without applying publisher actions;
- identifies installed or organization-provided packs;
- marks incompatible choices;
- always offers an accessible default;
- preserves user accessibility requirements;
- can reset per origin or globally.

Publisher logos and icons remain publisher content. An appearance may place
them appropriately but cannot replace them with the viewer logo.

## 25. Navigation and history

The address bar shows the logical destination entered or reached by the person.
Discovery endpoints do not silently replace it.

Navigation requirements:

- Back and Forward operate on logical destinations;
- Reload refreshes the current destination without duplicating history;
- redirects record their final logical destination according to HTTP behavior;
- action surface replacement does not add history;
- action redirect does add history;
- internal LUMUI links remain inside the viewer;
- external and unsupported links use host policy;
- cancellation stops obsolete parsing, validation, resource loading and
  rendering;
- stale load results never replace a newer navigation.

The viewer preserves scroll and focus on back/forward where safe.

## 26. Loading and discovery pipeline

For every top-level navigation:

1. Normalize input.
2. Require HTTPS except allowed loopback development.
3. Create a navigation operation with cancellation and limits.
4. Request LUMUI and HTML media types.
5. Follow bounded redirects.
6. Classify by verified media type and discriminator.
7. Follow a descriptor or discovery resource when returned.
8. Inspect advertised links in HTML.
9. Try the well-known endpoint when permitted.
10. Choose LUMUI, HTML fallback or a typed failure.
11. Parse and validate before rendering.
12. Commit only if the navigation operation is still current.

Limits cover bytes, redirects, discovery hops, nesting, components, strings,
assets, media, action input and total time.

## 27. Rendering pipeline

The renderer processes a validated surface in deterministic stages:

1. resolve requested page;
2. build semantic indexes;
3. resolve effective context;
4. select compatible component renderers;
5. compute profile composition;
6. compute accessible names and relationships;
7. create platform elements;
8. attach action bindings;
9. create focus order;
10. load bounded assets asynchronously;
11. apply state and validation;
12. reconcile focus and scroll;
13. announce the committed page or change;
14. publish diagnostics.

A stage returns typed success, fallback or error information. Exceptions do not
become normal protocol branching.

## 28. Component rendering and fallback

Each component renderer declares:

- supported kind and versions;
- profiles;
- output modes;
- required fields;
- optional fields;
- action support;
- accessibility behavior;
- fallback behavior.

Unsupported optional presentation falls back to registered semantics. Enabled
content or actions are never silently removed.

A component failure is isolated to the smallest safe semantic region. The
viewer may show a readable fallback and diagnostic identifier while unrelated
regions remain usable.

## 29. Forms, actions and state

The viewer creates inputs from the current surface and local interaction state.
The publisher remains authoritative after an action.

Action sequence:

1. confirm that the action is declared and enabled;
2. collect only fields in the action input schema;
3. perform local type and required-field checks;
4. associate local errors without inventing business validation;
5. obtain host confirmation when required;
6. create a unique message ID;
7. send origin, surface, revision, action and bounded input;
8. handle success, validation, challenge, permission, asynchronous or problem
   result;
9. reconcile the authoritative returned state;
10. move focus to the result, first error or retained control.

Buttons are disabled only when a duplicate operation would be unsafe. Progress
feedback exposes an accessible busy state.

## 30. Errors

Errors are categorized as:

- address;
- transport;
- discovery;
- authentication;
- parse;
- schema;
- policy;
- unsupported version;
- renderer compatibility;
- resource;
- action validation;
- action authorization;
- action conflict;
- internal viewer.

The person sees a concise explanation, safe recovery actions and a diagnostic
identifier. Developer tools show structured details. Secret values, cookies,
authorization headers and regulated content are redacted.

An error page is viewer-owned and visually distinct from publisher content.

## 31. Security and trust

The viewer enforces:

- HTTPS policy;
- origin checks;
- redirect and discovery limits;
- cookie isolation;
- credential mediation;
- response and resource bounds;
- strict schema validation;
- URL scheme allowlists;
- capability mediation;
- renderer-pack integrity;
- untrusted text escaping;
- sandboxing for graphics and extensions;
- trusted confirmation;
- safe download handling;
- log redaction.

Publisher content cannot draw over or imitate the address, permission,
authentication, certificate, download or dangerous-confirmation surfaces.

## 32. Authentication

Authentication is a host concern coordinated with the publisher.

The viewer may support:

- public access;
- origin-scoped cookie sessions;
- bearer credentials stored by a credential broker;
- OAuth-style system flows;
- device authentication;
- organization-managed identity.

Credentials are not exposed to renderers or diagnostic exporters. A publisher
authentication page remains publisher content; a credential or consent prompt
owned by the host remains trusted chrome.

## 33. Privacy

Viewer settings can reveal access needs. They are private host data.

The viewer:

- stores the minimum required settings;
- keeps global and per-origin values separate;
- does not publish settings by default;
- provides scope and reset controls;
- does not include secret component values in logs;
- redacts diagnostic exports;
- indicates when organization policy controls a setting;
- makes telemetry optional and documented;
- avoids cross-origin setting identifiers.

## 34. Caching and offline behavior

The cache stores representations according to HTTP validators and sensitivity
policy. It keys negotiated responses correctly and does not mix users or
origins.

Offline support:

- is explicit in the descriptor or installed application policy;
- identifies stale content;
- prevents unsafe mutation replay;
- queues only actions designed for replay;
- preserves idempotency keys;
- revalidates before displaying sensitive cached state;
- provides a clear `Try again` action.

## 35. Performance budgets

A viewer defines measurable budgets for:

- startup;
- first useful surface;
- navigation;
- parse and validation;
- initial render;
- setting changes;
- focus reconciliation;
- memory per document;
- image and media decoding;
- developer-tool capture.

The UI thread MUST NOT perform network access or unbounded parsing. Parsing,
validation and asset decoding are cancellable where the platform permits.

Virtualization MAY be used for large collections when it preserves semantic
order, keyboard access, item count and focus restoration.

## 36. Localization and writing systems

The viewer respects surface locale and direction while keeping trusted chrome
in the person's viewer language.

Requirements include:

- bidirectional text isolation;
- locale-aware numbers, dates and times;
- translated viewer controls and errors;
- layout mirroring where appropriate;
- no concatenated translatable sentences;
- font fallback;
- input method editor support;
- preserved semantic IDs independent from translated labels;
- accessible announcements in the correct language.

## 37. Developer tools design

Developer tools have six views.

### 37.1 Overview

Shows logical address, final resource, publisher, surface ID, revision,
versions, selected page, component count, conformance claim and effective
context.

### 37.2 Source

Shows exact received bytes when safe, formatted JSON, search, copy and save.
Formatting never replaces the exact-source view.

### 37.3 Semantic tree

Shows pages, regions and components by semantic ID. Selecting a node highlights
the corresponding rendered element without changing application focus unless
the person requests focus.

### 37.4 Network

Shows request method, logical and final address, redirects, status, media type,
timing, cache result and safe headers.

### 37.5 Actions

Shows action name, message ID, surface revision, timing and result category.
Inputs and headers follow redaction policy.

### 37.6 Problems

Shows parse, schema, policy, renderer, asset and action diagnostics with stable
codes, source paths and suggested corrections.

## 38. User-control design

The preferred control sequence is:

1. Start in Auto.
2. Keep Back, Forward, Reload and Address familiar.
3. Provide one visible View control.
4. Put Text, Contrast and Motion first.
5. Keep Standard/Guided and Visual/Screen reader separate.
6. Put device simulation and appearance under More options.
7. Show when a setting is global, site-specific, session-only or policy-owned.
8. Apply changes immediately with focus preservation.
9. Offer Undo when a change substantially restructures the view.
10. Keep Reset this site visible.
11. Keep developer simulation out of the ordinary accessibility flow.

The viewer SHOULD use a non-modal anchored panel on wide layouts and an
accessible full-height sheet on narrow layouts. Either form traps focus only
while truly modal and returns focus to the View control when closed.

## 39. Keyboard model

Recommended viewer shortcuts:

| Shortcut | Operation |
|---|---|
| `Ctrl+L` or platform equivalent | Focus address |
| `Ctrl+R` | Reload |
| `Alt+Left` | Back |
| `Alt+Right` | Forward |
| `Ctrl++` | Increase text |
| `Ctrl+-` | Decrease text |
| `Ctrl+0` | Reset text |
| `Ctrl+Shift+V` | Open View controls |
| `F12` | Toggle developer tools |
| `Ctrl+U` | Show received semantic source |
| `Escape` | Close the active viewer-owned panel or cancel navigation |

Shortcuts MUST NOT capture text-entry keys incorrectly and MUST expose platform
alternatives.

## 40. Touch, voice and switch access

Touch controls use suitable target size and spacing for the active profile.
Hover is never the only way to discover or perform an action.

Voice commands derive from accessible names and declared actions. The viewer
resolves ambiguity and confirms dangerous operations.

Switch navigation follows deterministic semantic order, exposes group
boundaries and never enters an inescapable cycle.

## 41. Extension and renderer packs

Renderer packs are installed through a trusted mechanism. A manifest declares
identity, version, integrity, supported documents, components, profiles,
outputs, appearances, accessibility and fallback.

Extensions:

- cannot bypass schema or origin validation;
- cannot read credentials;
- cannot grant capabilities;
- cannot replace viewer chrome;
- receive only required data;
- run with bounded resources;
- have a disable and recovery path;
- include conformance fixtures;
- use one stable responsibility per extension object.

## 42. Code quality architecture

Production viewer and website code MUST use explicit boundaries that make
security and accessibility behavior testable.

### 42.1 SOLID requirements

**Single responsibility:** A type has one reason to change. Navigation,
transport, parsing, validation, policy, context, rendering, actions, settings
and diagnostics remain separate.

**Open/closed:** New component renderers, transports, credential flows and
appearance packs are added behind documented interfaces. Core control flow does
not accumulate application-specific branches.

**Liskov substitution:** Implementations honor complete interface contracts,
including cancellation, errors and security behavior. A fallback renderer
cannot claim capabilities it silently omits.

**Interface segregation:** Interfaces remain small and role-specific. A
renderer does not depend on cookie storage; a validator does not depend on UI;
a settings reader does not require administrative mutation methods.

**Dependency inversion:** Policy and use-case services depend on ports.
Framework HTTP requests, file systems, native controls, clocks and network
clients remain adapters.

### 42.2 One object per file

Each production PHP or C# source file MUST declare at most one class, interface,
trait, enum, record or struct. The filename MUST match that declared object.

Each class-based production JavaScript module MUST declare at most one class.
Its filename MUST follow the project's documented module naming convention and
identify that responsibility.

Small private helper methods belong to the owning responsibility. A second
independently testable responsibility becomes a separate object and file.

Generated protocol vocabulary follows the same layout unless the generator and
verification rules explicitly document a generated exception.

### 42.3 Additional rules

- Prefer immutable validated models.
- Parse at boundaries and do not pass untyped dictionaries through the core.
- Inject clocks, transports, storage, policy and diagnostics.
- Avoid global mutable state and service locators.
- Keep protocol identifiers generated from canonical resources.
- Do not duplicate business rules in renderers.
- Do not branch on application route IDs in generic hosts.
- Represent expected failure with typed results.
- Reserve exceptions for unexpected or unrecoverable failures.
- Make cancellation explicit for I/O and long work.
- Redact at the point diagnostic data is created.
- Keep UI event handlers thin; call use-case services.
- Keep platform controls behind renderer adapters.
- Review every dependency for its trust and update boundary.

## 43. Recommended ports

The architecture SHOULD define equivalents of these small ports:

- `AddressLoader`
- `SurfaceParser`
- `SurfaceValidator`
- `PolicyEvaluator`
- `RenderContextResolver`
- `RendererCatalog`
- `SurfaceRenderer`
- `ActionCoordinator`
- `ConfirmationService`
- `CredentialBroker`
- `CapabilityBroker`
- `ViewerSettingsReader`
- `ViewerSettingsWriter`
- `NavigationHistory`
- `ViewerDiagnostics`
- `Clock`

Names may follow language conventions. Behavior matters more than names.

## 44. C# and ASP.NET implementation

A clean ASP.NET publisher places framework types at the edge:

```text
Endpoints/
  GetSurfaceEndpoint.cs
  PostActionEndpoint.cs
Application/
  GetSurface.cs
  InvokeAction.cs
Contracts/
  SurfaceDocument.cs
  ActionMessage.cs
Validation/
  SurfaceDocumentValidator.cs
Rendering/
  HtmlSurfaceRenderer.cs
Infrastructure/
  SystemClock.cs
```

Each file contains one declared object. Endpoints translate HTTP into use-case
inputs. Application services authorize and obtain domain state. Mappers create
LUMUI contracts. Validators apply canonical schemas. The HTML renderer adapter
produces the browser representation.

Recommended browser path:

1. Return LUMUI JSON when the request accepts `application/lumui+json`.
2. Otherwise pass the same validated document to an HTML renderer.
3. Return complete HTML and LUMUI discovery links.
4. Post declared actions to a dedicated endpoint.
5. Return typed LUMUI action results.

The native C# viewer uses the same separation but replaces ASP.NET adapters
with an HTTP client and Avalonia renderer adapters.

## 45. PHP implementation

The reference PHP shape is:

```text
Contracts/
  SurfaceProvider.php
  SurfaceRenderer.php
Http/
  WebPublisher.php
  ActionDispatcher.php
Protocol/
  SurfaceValidator.php
Application/
  AccountSurfaceProvider.php
  AccountActionHandler.php
```

The route script composes these objects. It does not contain business logic.
The provider maps application state to semantic documents. The publisher
performs content negotiation. `lumui-html-renderer` renders complete HTML.

## 46. C and C++ implementation

A C or C++ implementation SHOULD:

- use generated model types from canonical schemas where practical;
- define ownership and lifetime at every boundary;
- use bounded parsers;
- avoid exposing raw pointers across renderer plug-in boundaries;
- represent strings with explicit encoding;
- separate HTTP, validation, policy and rendering libraries;
- use fuzzing for parsers and action inputs;
- isolate third-party codecs;
- expose a stable C ABI when sharing an HTML renderer;
- keep one public type or object responsibility per source/header pair.

For browser delivery, the publisher can call an in-process renderer library,
invoke a local renderer service or publish LUMUI to an existing conforming
viewer.

## 47. Julia implementation

A Julia publisher can use an HTTP routing package, JSON model mapping and schema
validation while retaining the same boundaries.

Recommended modules separate transport adapters, domain use cases, LUMUI model
mapping, validation and rendering. Browser HTML may be produced by a native
Julia renderer adapter or a bounded internal call to the reference HTML
renderer.

Dynamic language features do not relax closed-schema validation. Published
objects still contain only declared fields and valid values.

## 48. Other language implementations

Java, Kotlin, Go, Rust, Python, Ruby and JavaScript implementations follow the
same sequence:

1. keep framework request objects at the adapter boundary;
2. obtain authorized domain state;
3. map to versioned LUMUI model objects;
4. validate against canonical resources;
5. negotiate semantic or HTML representation;
6. render through a conforming renderer;
7. dispatch actions through explicit use cases;
8. run shared fixtures and behavioral tests.

An implementation does not need to reproduce the internal API of the reference
modules. It reproduces observable protocol and conformance behavior.

## 49. HTML renderer integration contract

An HTML renderer accepts:

- one validated surface;
- selected page;
- base URL;
- public asset base;
- effective web render context;
- safe renderer configuration.

It returns:

- complete HTML;
- correct document metadata;
- semantic discovery links;
- stable component identifiers;
- accessible structure and controls;
- bounded renderer asset references.

The renderer does not:

- query application databases;
- authorize actions;
- mutate domain state;
- select credentials;
- accept publisher scripts or arbitrary styles;
- change action identifiers;
- decide host permissions.

## 50. Testing strategy

### 50.1 Unit tests

Test each policy and transformation in isolation:

- address normalization;
- media-type classification;
- redirect rules;
- schema errors;
- origin policy;
- context precedence;
- profile selection;
- component fallback;
- focus reconciliation;
- action messages;
- redaction.

### 50.2 Contract tests

Run shared canonical fixtures against every implementation. Valid fixtures
must be accepted. Invalid and unknown-field fixtures must be rejected with the
expected category.

### 50.3 Renderer tests

For each claimed component, profile, output and preference combination, test:

- semantic content;
- role and name;
- state and value;
- action identity;
- keyboard operation;
- focus;
- error association;
- reflow;
- contrast;
- reduced motion;
- fallback.

### 50.4 End-to-end tests

Cover discovery, redirects, authentication, navigation, actions, history,
reload, cancellation, offline state, settings persistence and reset.

### 50.5 Security tests

Cover malformed JSON, nesting bombs, oversized responses, redirect loops,
origin changes, unsafe schemes, injection, hostile assets, CSRF, replay,
credential leakage, log leakage and extension isolation.

### 50.6 Accessibility tests

Automated checks are necessary but insufficient. Include keyboard-only,
screen-reader, enlarged-text, high-contrast, reduced-motion, touch, voice and
switch evaluation on every claimed platform.

## 51. Conformance matrix

Every release maintains a machine-readable and human-readable matrix with:

- document versions;
- conformance classes;
- component kinds;
- action result kinds;
- profiles;
- outputs;
- interaction modes;
- accessibility preferences;
- authentication modes;
- media and graphics support;
- platform limitations;
- extension packs;
- test result and date.

Unsupported behavior is declared explicitly. Partial support is not presented
as complete compatibility.

## 52. Observability

Operational events use structured identifiers and categories. Metrics may
include duration, size, result category and renderer fallback counts.

Observability MUST NOT record:

- passwords;
- one-time codes;
- authorization headers;
- cookies;
- secret or regulated component values;
- full private documents by default;
- accessibility settings tied to cross-origin identity.

Diagnostic collection has bounded storage and retention.

## 53. Versioning and release

Viewer releases use explicit product versions and publish:

- supported LUMUI versions;
- renderer-pack versions;
- schema and vocabulary source revisions;
- conformance matrix;
- security update policy;
- migration notes;
- known limitations;
- reproducible build information where practical.

Protocol compatibility and viewer product version are separate. A viewer
product update does not silently redefine a LUMUI component.

## 54. Implementation roadmap

### Phase 1: dependable core

- address, navigation and cancellation;
- discovery and complete validation;
- Renderer Core;
- automatic default profile;
- keyboard, focus, text scaling, contrast and reduced motion;
- actions and reconciliation;
- typed error pages;
- conformance fixtures.

### Phase 2: excellent user control

- unified View panel;
- independent accessibility settings;
- global, site and session scopes;
- Standard and Guided interaction;
- visual and screen-reader-first output;
- reset and undo;
- profile previews;
- setting explanations.

### Phase 3: browser integration

- complete HTML renderer integration;
- progressive enhancement;
- deep links and metadata;
- server and client equivalence tests;
- renderer asset hardening.

### Phase 4: professional diagnostics

- source, tree, network, actions and problems views;
- redacted export;
- support matrix;
- performance diagnostics.

### Phase 5: ecosystem

- signed renderer packs;
- additional language adapters;
- shared conformance runner;
- accessibility certification process;
- organization policy and managed deployment;
- offline and installed application profiles.

## 55. Release acceptance criteria

A release is ready only when:

- all claimed schemas and fixtures pass;
- each production file satisfies the one-object rule;
- dependency boundaries have no framework leakage into core policy;
- the default view completes representative tasks without configuration;
- every View control is keyboard and assistive-technology operable;
- accessibility settings combine correctly;
- setting changes preserve state and focus;
- all claimed profiles provide suitable composition;
- action replay, origin and confirmation tests pass;
- no enabled content or action disappears silently;
- errors provide recovery;
- diagnostic exports are redacted;
- performance budgets pass on minimum supported hardware;
- the conformance matrix and known limitations are published.

## Appendix A. Recommended View panel

```text
View
├─ Text size          Default  [−] [100%] [+]
├─ Contrast           System | Standard | High
├─ Motion             System | Reduced
├─ Layout             Comfortable | Compact | Spacious
├─ Interaction        Standard | Guided
├─ Output             Visual | Screen reader
├─ More options
│  ├─ Profile         Auto | Web | Desktop | Tablet | Phone | Watch | Kiosk | Appliance
│  ├─ Appearance      Default | Platform | Installed choices
│  ├─ Transparency    System | Reduced | Opaque
│  ├─ Target size     System | Large
│  └─ Input preview   Off | Touch | Keyboard | Voice | Switch
├─ Save for           This session | This site | All sites
└─ Reset this site
```

The actual UI adapts to platform and available capabilities. Unsupported
choices are omitted or disabled with an explanation.

## Appendix B. User-facing terminology

Prefer:

- View
- Text size
- Contrast
- Motion
- Layout
- Interaction
- Standard
- Guided
- Output
- Screen reader
- Preview device
- Appearance
- Reset this site
- Return to my device

Reserve protocol identifiers such as `smartphone.portrait.default` for
developer tools and machine-readable configuration.

## Appendix C. Minimum diagnostic codes

Implementations SHOULD provide stable codes for:

- invalid address;
- insecure transport;
- redirect limit;
- discovery loop;
- unsupported media type;
- invalid JSON;
- schema violation;
- unsupported document version;
- unsafe resource;
- origin-policy violation;
- unsupported component;
- missing fallback;
- action unavailable;
- stale revision;
- confirmation required;
- permission required;
- authentication required;
- renderer failure;
- cancelled navigation;
- offline unavailable.

Codes are stable; localized messages may change.

## Appendix D. Architecture review checklist

- Does every type have one reason to change?
- Does every PHP, C# and class-based JavaScript file declare at most one object?
- Does the filename match the declared object?
- Can transport be replaced without changing validation?
- Can rendering be replaced without changing business state?
- Can settings storage be replaced without changing context precedence?
- Can diagnostics be disabled without changing behavior?
- Are publisher strings treated as untrusted?
- Are all network and long-running operations cancellable?
- Are effective settings immutable during one render commit?
- Are accessibility settings independent and composable?
- Are host chrome and publisher content unmistakably separate?
- Can every unsupported feature explain its fallback?
- Does the conformance claim match tested behavior?

## Appendix E. Publisher implementation checklist

- Serve a descriptor and route-level surfaces.
- Keep ordinary URLs authoritative.
- Negotiate LUMUI and HTML.
- Build surfaces from authorized domain state.
- Validate every surface before publication.
- Use declared component and action IDs.
- Implement revisions and conditional requests.
- Validate every action server-side.
- Protect authenticated mutations.
- Return typed action results and errors.
- Include accessible labels, descriptions and alternatives.
- Provide semantic fallback for rich media and extensions.
- Integrate a conforming HTML renderer for ordinary browsers.
- Run canonical conformance fixtures.
- Publish supported versions and limitations.

## Appendix F. Viewer implementation checklist

- Load, discover, validate and bound every resource.
- Start in a useful Auto view.
- Keep profile, appearance, output, interaction and preferences separate.
- Provide a visible and accessible View control.
- Make text, contrast and motion easy to change.
- Combine accessibility settings.
- Preserve state, focus and action identity across view changes.
- Keep security and permissions in trusted chrome.
- Provide semantic fallback.
- Reconcile action results safely.
- Expose typed recovery errors.
- Redact diagnostics.
- Test every claimed profile and output.
- Publish a conformance matrix.

