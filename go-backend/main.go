package main

import (
	"crypto/sha1"
	"encoding/json"
	"errors"
	"fmt"
	"html/template"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

const (
	defaultSiteHost      = "ipx.ee"
	defaultAPIHost       = "api.ipx.ee"
	defaultV4Host        = "v4.ipx.ee"
	defaultV6Host        = "v6.ipx.ee"
	defaultPort          = "18080"
	defaultIPAPIEndpoint = "https://pro.ip-api.com/json/"
	defaultIPAPIKey      = ""
	defaultIPAPIFields   = "21229083"
	defaultIPAPILang     = "en"
	defaultGeoHTTPTimout = 5
	defaultGeoCacheTTL   = 86400
	geoCacheSchema       = 9
)

type app struct {
	siteHost string
	apiHost  string
	v4Host   string
	v6Host   string
	port     string
	geo      *geoIPClient
}

type geoIPClient struct {
	httpClient *http.Client
	endpoint   string
	apiKey     string
	fields     string
	lang       string
	cacheDir   string
	cacheTTL   time.Duration
}

type geoCacheEntry struct {
	Schema    int           `json:"schema"`
	FetchedAt int64         `json:"fetched_at"`
	ExpiresAt int64         `json:"expires_at"`
	Data      geoAPIPayload `json:"data"`
}

type geoPayload struct {
	IP            string   `json:"ip"`
	Lookup        string   `json:"lookup"`
	Country       string   `json:"country,omitempty"`
	CountryCode   string   `json:"country_code,omitempty"`
	Continent     string   `json:"continent,omitempty"`
	ContinentCode string   `json:"continent_code,omitempty"`
	Region        string   `json:"region,omitempty"`
	Province      string   `json:"province,omitempty"`
	City          string   `json:"city,omitempty"`
	District      string   `json:"district,omitempty"`
	Zip           string   `json:"zip,omitempty"`
	Latitude      *float64 `json:"latitude,omitempty"`
	Longitude     *float64 `json:"longitude,omitempty"`
	Timezone      string   `json:"timezone,omitempty"`
	Offset        *int     `json:"offset,omitempty"`
	Currency      string   `json:"currency,omitempty"`
	ISP           string   `json:"isp,omitempty"`
	Org           string   `json:"org,omitempty"`
	ASN           string   `json:"asn,omitempty"`
	ASName        string   `json:"as_name,omitempty"`
	Reverse       string   `json:"reverse,omitempty"`
	IsMobile      *bool    `json:"is_mobile,omitempty"`
	IsProxy       *bool    `json:"is_proxy,omitempty"`
	IsHosting     *bool    `json:"is_hosting,omitempty"`
	Flag          string   `json:"flag,omitempty"`
}

type geoAPIPayload struct {
	OK        bool           `json:"ok"`
	Data      geoPayload     `json:"data"`
	Raw       map[string]any `json:"raw,omitempty"`
	RateLimit *rateLimitInfo `json:"rate_limit,omitempty"`
}

type rateLimitInfo struct {
	Remaining *int `json:"remaining,omitempty"`
	ResetIn   *int `json:"reset_in,omitempty"`
}

type lookupOptions struct {
	Fields string
	Lang   string
	Raw    bool
}

type pageData struct {
	SiteHost    string
	APIHost     string
	V4Host      string
	V6Host      string
	LookupQuery string
}

func main() {
	projectRoot := envOr("PROJECT_ROOT", "..")
	projectRoot, _ = filepath.Abs(projectRoot)

	a := &app{
		siteHost: normalizeHost(envOr("SITE_HOST", defaultSiteHost)),
		apiHost:  normalizeHost(envOr("API_HOST", defaultAPIHost)),
		v4Host:   normalizeHost(envOr("V4_HOST", defaultV4Host)),
		v6Host:   normalizeHost(envOr("V6_HOST", defaultV6Host)),
		port:     envOr("PORT", defaultPort),
		geo:      newGeoIPClient(projectRoot),
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/", a.handleRequest)

	server := &http.Server{
		Addr:              ":" + a.port,
		Handler:           withCORS(mux),
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       15 * time.Second,
		WriteTimeout:      25 * time.Second,
		IdleTimeout:       60 * time.Second,
	}

	fmt.Printf("ipx.ee service listening on :%s\n", a.port)
	fmt.Printf("hosts: site=%s api=%s v4=%s v6=%s\n", a.siteHost, a.apiHost, a.v4Host, a.v6Host)
	fmt.Printf("geo upstream=%s fields=%s cache=%s ttl=%s\n", a.geo.endpoint, a.geo.fields, a.geo.cacheDir, a.geo.cacheTTL)

	if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		panic(err)
	}
}

func newGeoIPClient(projectRoot string) *geoIPClient {
	timeoutSeconds := parseInt(envOr("GEOIP_HTTP_TIMEOUT", strconv.Itoa(defaultGeoHTTPTimout)), defaultGeoHTTPTimout)
	cacheTTLSeconds := parseInt(envOr("GEOIP_CACHE_TTL", strconv.Itoa(defaultGeoCacheTTL)), defaultGeoCacheTTL)
	endpoint := strings.TrimSpace(envOr("IP_API_ENDPOINT", defaultIPAPIEndpoint))
	apiKey := strings.TrimSpace(envOr("IP_API_KEY", defaultIPAPIKey))
	fields := strings.TrimSpace(envOr("IP_API_FIELDS", defaultIPAPIFields))
	lang := strings.TrimSpace(envOr("IP_API_LANG", defaultIPAPILang))

	if fields == "" {
		fields = defaultIPAPIFields
	}
	if lang == "" {
		lang = defaultIPAPILang
	}

	return &geoIPClient{
		httpClient: &http.Client{
			Timeout: time.Duration(maxInt(2, timeoutSeconds)) * time.Second,
		},
		endpoint: strings.TrimRight(endpoint, "/") + "/",
		apiKey:   apiKey,
		fields:   fields,
		lang:     lang,
		cacheDir: envOr("GEOIP_CACHE_DIR", filepath.Join(projectRoot, "data", "geoip-cache")),
		cacheTTL: time.Duration(maxInt(60, cacheTTLSeconds)) * time.Second,
	}
}

func withCORS(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Methods", "GET, HEAD, OPTIONS")
		w.Header().Set("Access-Control-Expose-Headers", "Content-Length, Content-Type")
		w.Header().Set("Access-Control-Max-Age", "86400")
		w.Header().Set("Cross-Origin-Resource-Policy", "cross-origin")

		requestHeaders := strings.TrimSpace(r.Header.Get("Access-Control-Request-Headers"))
		if requestHeaders != "" {
			w.Header().Set("Access-Control-Allow-Headers", requestHeaders)
			w.Header().Add("Vary", "Access-Control-Request-Headers")
		} else {
			w.Header().Set("Access-Control-Allow-Headers", "Accept, Authorization, Content-Type, Origin, X-Requested-With")
		}

		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusNoContent)
			return
		}
		next.ServeHTTP(w, r)
	})
}

func (a *app) handleRequest(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet && r.Method != http.MethodHead {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	if r.URL.Path == "/health" {
		jsonResponse(w, map[string]any{"ok": true}, http.StatusOK)
		return
	}
	if strings.HasPrefix(r.URL.Path, "/api/") {
		a.handleLocalAPI(w, r)
		return
	}

	host := normalizeHost(r.Host)
	switch host {
	case a.apiHost:
		a.handleAPIHost(w, r)
	case a.v4Host, a.v6Host:
		a.handleIPOnlyHost(w, r)
	default:
		a.handleSiteHost(w, r)
	}
}

func (a *app) handleSiteHost(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path == "/" {
		if q := strings.TrimSpace(r.URL.Query().Get("q")); q != "" {
			q = strings.Trim(strings.TrimSpace(decodePathValue(q)), "/")
			if q != "" {
				http.Redirect(w, r, "/"+url.PathEscape(q), http.StatusFound)
				return
			}
		}

		clientIP := getClientIP(r)
		if isCLIRequest(r) {
			textResponse(w, clientIP, http.StatusOK)
			return
		}

		data := pageData{
			SiteHost: a.siteHost,
			APIHost:  a.apiHost,
			V4Host:   a.v4Host,
			V6Host:   a.v6Host,
		}
		htmlResponse(w, renderTemplate(siteTemplate, data), http.StatusOK)
		return
	}

	query := strings.Trim(strings.TrimSpace(strings.TrimPrefix(r.URL.Path, "/")), "/")
	query = decodePathValue(query)
	if query == "" || strings.Contains(query, "/") {
		http.NotFound(w, r)
		return
	}

	if isCLIRequest(r) {
		a.respondGeoLookup(w, r, query, query)
		return
	}

	data := pageData{
		SiteHost:    a.siteHost,
		APIHost:     a.apiHost,
		V4Host:      a.v4Host,
		V6Host:      a.v6Host,
		LookupQuery: query,
	}
	htmlResponse(w, renderTemplate(siteTemplate, data), http.StatusOK)
}

func (a *app) handleIPOnlyHost(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path != "/" {
		http.NotFound(w, r)
		return
	}

	wantsJSON := strings.EqualFold(strings.TrimSpace(r.URL.Query().Get("format")), "json")
	clientIP := getClientIP(r)
	if clientIP == "" {
		if wantsJSON {
			jsonResponse(w, map[string]any{"error": "Unable to detect client IP"}, http.StatusBadRequest)
			return
		}
		textResponse(w, "", http.StatusBadRequest)
		return
	}

	expectedFamily := a.expectedProbeFamily(normalizeHost(r.Host))
	if expectedFamily != "" && ipFamily(clientIP) != expectedFamily {
		if wantsJSON {
			jsonResponse(w, map[string]any{
				"error":           strings.ToUpper(expectedFamily) + " unavailable",
				"expected_family": expectedFamily,
				"detected_family": ipFamily(clientIP),
			}, http.StatusConflict)
			return
		}
		textResponse(w, strings.ToUpper(expectedFamily)+" unavailable", http.StatusConflict)
		return
	}

	if wantsJSON {
		a.respondGeoLookup(w, r, clientIP, clientIP)
		return
	}

	textResponse(w, clientIP, http.StatusOK)
}

func (a *app) handleAPIHost(w http.ResponseWriter, r *http.Request) {
	switch {
	case r.URL.Path == "/":
		jsonResponse(w, map[string]any{
			"service": "ipx.ee api",
			"routes": []string{
				"/ip",
				"/{ip-or-domain}",
				"/fields",
				"/health",
			},
			"example": "https://api.ipx.ee/8.8.8.8",
		}, http.StatusOK)
		return
	case r.URL.Path == "/health":
		jsonResponse(w, map[string]any{"ok": true}, http.StatusOK)
		return
	case r.URL.Path == "/fields":
		jsonResponse(w, apiFields(), http.StatusOK)
		return
	case r.URL.Path == "/ip":
		clientIP := getClientIP(r)
		if clientIP == "" {
			jsonResponse(w, map[string]any{"error": "Unable to detect client IP"}, http.StatusBadRequest)
			return
		}
		if isNonPublicIP(clientIP) {
			jsonResponse(w, localGeoPayload(clientIP, optionsFromRequest(r)), http.StatusOK)
			return
		}
		a.respondGeoLookup(w, r, clientIP, clientIP)
		return
	case strings.HasPrefix(r.URL.Path, "/ip/"):
		query := strings.TrimSpace(strings.TrimPrefix(r.URL.Path, "/ip/"))
		query = decodePathValue(query)
		if query == "" {
			jsonResponse(w, map[string]any{"error": "Missing query"}, http.StatusBadRequest)
			return
		}
		a.respondGeoLookup(w, r, query, query)
		return
	case r.URL.Path != "/":
		query := strings.TrimPrefix(r.URL.Path, "/")
		query = decodePathValue(query)
		if query == "" || query == "favicon.ico" {
			http.NotFound(w, r)
			return
		}
		a.respondGeoLookup(w, r, query, query)
		return
	default:
		http.NotFound(w, r)
	}
}

func (a *app) handleLocalAPI(w http.ResponseWriter, r *http.Request) {
	switch {
	case r.URL.Path == "/api/health":
		jsonResponse(w, map[string]any{"ok": true}, http.StatusOK)
		return
	case r.URL.Path == "/api/fields":
		jsonResponse(w, apiFields(), http.StatusOK)
		return
	case r.URL.Path == "/api/ip":
		clientIP := getClientIP(r)
		if clientIP == "" {
			jsonResponse(w, map[string]any{"error": "Unable to detect client IP"}, http.StatusBadRequest)
			return
		}
		if isNonPublicIP(clientIP) {
			jsonResponse(w, localGeoPayload(clientIP, optionsFromRequest(r)), http.StatusOK)
			return
		}
		a.respondGeoLookup(w, r, clientIP, clientIP)
		return
	case strings.HasPrefix(r.URL.Path, "/api/lookup/"):
		query := strings.TrimSpace(strings.TrimPrefix(r.URL.Path, "/api/lookup/"))
		query = decodePathValue(query)
		if query == "" {
			jsonResponse(w, map[string]any{"error": "Missing query"}, http.StatusBadRequest)
			return
		}
		a.respondGeoLookup(w, r, query, query)
		return
	case strings.HasPrefix(r.URL.Path, "/api/raw/"):
		query := strings.TrimSpace(strings.TrimPrefix(r.URL.Path, "/api/raw/"))
		query = decodePathValue(query)
		if query == "" {
			jsonResponse(w, map[string]any{"error": "Missing query"}, http.StatusBadRequest)
			return
		}
		opts := optionsFromRequest(r)
		opts.Raw = true
		a.respondGeoLookupWithOptions(w, query, query, opts)
		return
	default:
		http.NotFound(w, r)
	}
}

func (a *app) respondGeoLookup(w http.ResponseWriter, r *http.Request, lookup, fallbackIP string) {
	a.respondGeoLookupWithOptions(w, lookup, fallbackIP, optionsFromRequest(r))
}

func (a *app) respondGeoLookupWithOptions(w http.ResponseWriter, lookup, fallbackIP string, opts lookupOptions) {
	data, status, err := a.geo.Lookup(lookup, opts)
	if err != nil {
		jsonResponse(w, map[string]any{
			"ok":     false,
			"error":  err.Error(),
			"lookup": lookup,
			"ip":     fallbackIP,
		}, status)
		return
	}
	jsonResponse(w, data, status)
}

func localGeoPayload(ip string, opts lookupOptions) geoAPIPayload {
	opts = normalizeLookupOptions(opts, defaultIPAPIFields, defaultIPAPILang)
	localCountry, localISP, localOrg := localizedLocalNetwork(opts.Lang)
	data := geoPayload{
		IP:      ip,
		Lookup:  ip,
		ISP:     localISP,
		Org:     localOrg,
		Country: localCountry,
	}
	raw := map[string]any{
		"status":  "success",
		"query":   ip,
		"country": localCountry,
		"isp":     data.ISP,
		"org":     data.Org,
	}
	payload := geoAPIPayload{
		OK:   true,
		Data: data,
	}
	if opts.Raw {
		payload.Raw = raw
	}
	return payload
}

func (g *geoIPClient) Lookup(query string, opts lookupOptions) (geoAPIPayload, int, error) {
	query = strings.TrimSpace(query)
	opts = normalizeLookupOptions(opts, g.fields, g.lang)
	if query == "" {
		return geoAPIPayload{}, http.StatusBadRequest, errors.New("Missing query")
	}

	if cached, ok := g.readCache(query, opts); ok {
		return cached, http.StatusOK, nil
	}

	req, err := http.NewRequest(http.MethodGet, g.buildURL(query, opts), nil)
	if err != nil {
		return geoAPIPayload{}, http.StatusServiceUnavailable, errors.New("GeoIP request build failed")
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "ipx.ee/geoip")

	resp, err := g.httpClient.Do(req)
	if err != nil {
		return geoAPIPayload{}, http.StatusServiceUnavailable, errors.New("GeoIP lookup failed")
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return geoAPIPayload{}, http.StatusServiceUnavailable, errors.New("GeoIP lookup failed")
	}

	var payload map[string]any
	if strings.TrimSpace(string(body)) != "" {
		if err := json.Unmarshal(body, &payload); err != nil {
			return geoAPIPayload{}, http.StatusServiceUnavailable, errors.New("GeoIP lookup returned invalid JSON")
		}
	}

	statusText := strings.TrimSpace(toString(payload["status"]))
	if statusText != "" && statusText != "success" {
		message := strings.TrimSpace(toString(payload["message"]))
		if message == "" {
			message = "GeoIP lookup failed"
		}
		return geoAPIPayload{}, statusFromMessage(message), errors.New(message)
	}

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return geoAPIPayload{}, http.StatusServiceUnavailable, errors.New("GeoIP lookup failed")
	}
	if len(payload) == 0 || !hasGeoPayload(payload) {
		return geoAPIPayload{}, http.StatusNotFound, errors.New("GeoIP lookup returned no data")
	}

	data := geoAPIPayload{
		OK:        true,
		Data:      mapGeoPayload(query, payload),
		RateLimit: rateLimitFromHeaders(resp.Header),
	}
	if opts.Raw {
		data.Raw = payload
	}
	g.writeCache(query, opts, data)
	return data, http.StatusOK, nil
}

func (g *geoIPClient) buildURL(query string, opts lookupOptions) string {
	values := url.Values{}
	if g.apiKey != "" {
		values.Set("key", g.apiKey)
	}
	values.Set("fields", fieldsForUpstream(opts.Fields))
	values.Set("lang", opts.Lang)
	return g.endpoint + url.PathEscape(query) + "?" + values.Encode()
}

// fieldsForUpstream prefers named fields so Pro API bit quirks cannot drop flags like hosting.
func fieldsForUpstream(fields string) string {
	fields = strings.TrimSpace(fields)
	if fields == "" {
		return strings.Join(commonIPAPIFieldNames(), ",")
	}
	if mask, err := strconv.ParseUint(fields, 10, 64); err == nil {
		names := decodeFieldsBitmask(mask)
		if len(names) > 0 {
			return strings.Join(names, ",")
		}
	}
	return fields
}

func commonIPAPIFieldNames() []string {
	return []string{
		"status", "message", "query", "country", "countryCode", "regionName", "city",
		"isp", "org", "as", "asname", "mobile", "proxy", "hosting",
	}
}

func decodeFieldsBitmask(mask uint64) []string {
	type fieldBit struct {
		name string
		bit  uint64
	}
	order := []fieldBit{
		{"status", 16384},
		{"message", 32768},
		{"continent", 1048576},
		{"continentCode", 2097152},
		{"country", 1},
		{"countryCode", 2},
		{"region", 4},
		{"regionName", 8},
		{"city", 16},
		{"district", 524288},
		{"zip", 32},
		{"lat", 64},
		{"lon", 128},
		{"timezone", 256},
		{"offset", 33554432},
		{"currency", 8388608},
		{"isp", 512},
		{"org", 1024},
		{"as", 2048},
		{"asname", 4194304},
		{"reverse", 4096},
		{"mobile", 65536},
		{"proxy", 131072},
		{"hosting", 16777216},
		{"query", 8192},
	}
	names := make([]string, 0, len(order))
	for _, item := range order {
		if mask&item.bit != 0 {
			names = append(names, item.name)
		}
	}
	return names
}

func (g *geoIPClient) cachePath(query string, opts lookupOptions) string {
	cacheKey := strings.Join([]string{
		strings.ToLower(strings.TrimSpace(query)),
		g.endpoint,
		opts.Fields,
		opts.Lang,
		strconv.FormatBool(opts.Raw),
	}, "|")
	sum := sha1.Sum([]byte(cacheKey))
	return filepath.Join(g.cacheDir, fmt.Sprintf("%x.json", sum))
}

func (g *geoIPClient) readCache(query string, opts lookupOptions) (geoAPIPayload, bool) {
	raw, err := os.ReadFile(g.cachePath(query, opts))
	if err != nil {
		return geoAPIPayload{}, false
	}

	var entry geoCacheEntry
	if err := json.Unmarshal(raw, &entry); err != nil {
		return geoAPIPayload{}, false
	}
	if entry.Schema != geoCacheSchema || entry.ExpiresAt < time.Now().Unix() {
		return geoAPIPayload{}, false
	}
	return entry.Data, true
}

func (g *geoIPClient) writeCache(query string, opts lookupOptions, data geoAPIPayload) {
	_ = ensureDir(g.cacheDir)
	entry := geoCacheEntry{
		Schema:    geoCacheSchema,
		FetchedAt: time.Now().Unix(),
		ExpiresAt: time.Now().Add(g.cacheTTL).Unix(),
		Data:      data,
	}
	body, err := json.MarshalIndent(entry, "", "  ")
	if err != nil {
		return
	}
	_ = os.WriteFile(g.cachePath(query, opts), body, 0o644)
}

func mapGeoPayload(lookup string, payload map[string]any) geoPayload {
	ip := strings.TrimSpace(toString(payload["query"]))
	if ip == "" {
		ip = lookup
	}
	asn := normalizeASN(strings.TrimSpace(toString(payload["as"])))
	rawASName := strings.TrimSpace(toString(payload["asname"]))
	isp := strings.TrimSpace(toString(payload["isp"]))
	org := strings.TrimSpace(toString(payload["org"]))
	countryCode := strings.ToUpper(strings.TrimSpace(toString(payload["countryCode"])))
	flag := ""
	if countryCode != "" {
		flag = "https://flagcdn.io/" + strings.ToLower(countryCode) + ".svg"
	}

	return geoPayload{
		IP:            ip,
		Lookup:        lookup,
		Continent:     strings.TrimSpace(toString(payload["continent"])),
		ContinentCode: strings.TrimSpace(toString(payload["continentCode"])),
		ASN:           asn,
		ASName:        preferredASName(rawASName, org, isp),
		ISP:           isp,
		Org:           org,
		IsProxy:       toBoolPtr(payload["proxy"]),
		IsHosting:     toBoolPtr(payload["hosting"]),
		IsMobile:      toBoolPtr(payload["mobile"]),
		Country:       strings.TrimSpace(toString(payload["country"])),
		CountryCode:   countryCode,
		Region:        strings.TrimSpace(toString(payload["region"])),
		Province:      strings.TrimSpace(toString(payload["regionName"])),
		City:          strings.TrimSpace(toString(payload["city"])),
		District:      strings.TrimSpace(toString(payload["district"])),
		Zip:           strings.TrimSpace(toString(payload["zip"])),
		Latitude:      toFloatPtr(payload["lat"]),
		Longitude:     toFloatPtr(payload["lon"]),
		Timezone:      strings.TrimSpace(toString(payload["timezone"])),
		Offset:        toIntPtr(payload["offset"]),
		Currency:      strings.TrimSpace(toString(payload["currency"])),
		Reverse:       strings.TrimSpace(toString(payload["reverse"])),
		Flag:          flag,
	}
}

func hasGeoPayload(payload map[string]any) bool {
	for _, key := range []string{"continent", "continentCode", "country", "countryCode", "region", "regionName", "city", "district", "zip", "timezone", "currency", "isp", "org", "as", "asname", "reverse", "lat", "lon", "query"} {
		if strings.TrimSpace(toString(payload[key])) != "" {
			return true
		}
	}
	return false
}

func optionsFromRequest(r *http.Request) lookupOptions {
	rawText := strings.ToLower(strings.TrimSpace(r.URL.Query().Get("raw")))
	return lookupOptions{
		Fields: strings.TrimSpace(r.URL.Query().Get("fields")),
		Lang:   strings.TrimSpace(r.URL.Query().Get("lang")),
		Raw:    rawText == "1" || rawText == "true" || rawText == "yes",
	}
}

func normalizeLookupOptions(opts lookupOptions, defaultFields, defaultLang string) lookupOptions {
	opts.Fields = sanitizeFields(opts.Fields, defaultFields)
	opts.Lang = sanitizeLang(opts.Lang, defaultLang)
	return opts
}

func sanitizeFields(fields, fallback string) string {
	fields = strings.TrimSpace(fields)
	if fields == "" {
		fields = fallback
	}
	if fields == "" {
		fields = strings.Join(defaultIPAPIFieldNames(), ",")
	}
	fields = strings.ReplaceAll(fields, " ", "")
	return fields
}

func sanitizeLang(lang, fallback string) string {
	lang = strings.TrimSpace(lang)
	if lang == "" {
		lang = fallback
	}
	switch lang {
	case "en", "de", "es", "pt-BR", "fr", "ja", "zh-CN", "ru":
		return lang
	default:
		return "en"
	}
}

// localizedLocalNetwork returns localized labels for loopback / private replies.
func localizedLocalNetwork(lang string) (country, isp, org string) {
	switch lang {
	case "zh-CN":
		return "本地网络", "本地开发", "回环 / 私有网络"
	case "ja":
		return "ローカルネットワーク", "ローカル開発", "ループバック / プライベートネットワーク"
	case "de":
		return "Lokales Netzwerk", "Lokale Entwicklung", "Loopback / privates Netzwerk"
	case "fr":
		return "Réseau local", "Développement local", "Loopback / réseau privé"
	case "es":
		return "Red local", "Desarrollo local", "Loopback / red privada"
	case "pt-BR":
		return "Rede local", "Desenvolvimento local", "Loopback / rede privada"
	case "ru":
		return "Локальная сеть", "Локальная разработка", "Loopback / частная сеть"
	default:
		return "Local network", "Local development", "Loopback / private network"
	}
}

func rateLimitFromHeaders(header http.Header) *rateLimitInfo {
	rl := toHeaderIntPtr(header.Get("X-Rl"))
	ttl := toHeaderIntPtr(header.Get("X-Ttl"))
	if rl == nil && ttl == nil {
		return nil
	}
	return &rateLimitInfo{Remaining: rl, ResetIn: ttl}
}

func toHeaderIntPtr(value string) *int {
	value = strings.TrimSpace(value)
	if value == "" {
		return nil
	}
	parsed, err := strconv.Atoi(value)
	if err != nil {
		return nil
	}
	return &parsed
}

func defaultIPAPIFieldNames() []string {
	return []string{
		"status", "message", "continent", "continentCode", "country", "countryCode",
		"region", "regionName", "city", "district", "zip", "lat", "lon", "timezone",
		"offset", "currency", "isp", "org", "as", "asname", "reverse", "mobile",
		"proxy", "hosting", "query",
	}
}

func apiFields() map[string]any {
	type field struct {
		Name        string `json:"name"`
		Bit         int    `json:"bit"`
		OutputKey   string `json:"output_key,omitempty"`
		Description string `json:"description"`
		Type        string `json:"type"`
	}

	fields := []field{
		{"status", 16384, "", "success or fail", "string"},
		{"message", 32768, "", "failure reason such as private range, reserved range, or invalid query", "string"},
		{"continent", 1048576, "continent", "Continent name", "string"},
		{"continentCode", 2097152, "continent_code", "Two-letter continent code", "string"},
		{"country", 1, "country", "Country name", "string"},
		{"countryCode", 2, "country_code", "Two-letter country code", "string"},
		{"region", 4, "region", "Region/state short code", "string"},
		{"regionName", 8, "province", "Region/state name", "string"},
		{"city", 16, "city", "City name", "string"},
		{"district", 524288, "district", "District or subdivision", "string"},
		{"zip", 32, "zip", "Zip or postal code", "string"},
		{"lat", 64, "latitude", "Latitude", "float"},
		{"lon", 128, "longitude", "Longitude", "float"},
		{"timezone", 256, "timezone", "Timezone identifier", "string"},
		{"offset", 33554432, "offset", "Timezone UTC DST offset in seconds", "int"},
		{"currency", 8388608, "currency", "National currency code", "string"},
		{"isp", 512, "isp", "ISP name", "string"},
		{"org", 1024, "org", "Organization name", "string"},
		{"as", 2048, "asn", "AS number and organization", "string"},
		{"asname", 4194304, "as_name", "AS name", "string"},
		{"reverse", 4096, "reverse", "Reverse DNS hostname", "string"},
		{"mobile", 65536, "is_mobile", "Mobile network flag", "bool"},
		{"proxy", 131072, "is_proxy", "Proxy, VPN, or Tor flag", "bool"},
		{"hosting", 16777216, "is_hosting", "Hosting, colocated, or data center flag", "bool"},
		{"query", 8192, "ip", "IP used for the query", "string"},
	}

	return map[string]any{
		"fields":        fields,
		"default":       defaultIPAPIFields,
		"default_names": defaultIPAPIFieldNames(),
		"languages":     []string{"en", "de", "es", "pt-BR", "fr", "ja", "zh-CN", "ru"},
		"hosts": map[string]string{
			"site": "https://ipx.ee",
			"api":  "https://api.ipx.ee",
		},
		"examples": []string{
			"https://api.ipx.ee/8.8.8.8",
			"https://api.ipx.ee/ip",
			"https://api.ipx.ee/8.8.8.8?fields=21229083&lang=zh-CN",
		},
	}
}

func statusFromMessage(message string) int {
	switch strings.ToLower(strings.TrimSpace(message)) {
	case "private range", "reserved range", "invalid query":
		return http.StatusBadRequest
	default:
		return http.StatusServiceUnavailable
	}
}

func getClientIP(r *http.Request) string {
	if cfip := strings.TrimSpace(r.Header.Get("CF-Connecting-IP")); cfip != "" {
		if net.ParseIP(cfip) != nil {
			return normalizeIP(cfip)
		}
	}

	if trueClientIP := strings.TrimSpace(r.Header.Get("True-Client-IP")); trueClientIP != "" {
		if net.ParseIP(trueClientIP) != nil {
			return normalizeIP(trueClientIP)
		}
	}

	if xff := strings.TrimSpace(r.Header.Get("X-Forwarded-For")); xff != "" {
		parts := strings.Split(xff, ",")
		candidate := strings.TrimSpace(parts[0])
		if net.ParseIP(candidate) != nil {
			return normalizeIP(candidate)
		}
	}

	if xr := strings.TrimSpace(r.Header.Get("X-Real-IP")); xr != "" {
		if net.ParseIP(xr) != nil {
			return normalizeIP(xr)
		}
	}

	host, _, err := net.SplitHostPort(strings.TrimSpace(r.RemoteAddr))
	if err == nil && net.ParseIP(host) != nil {
		return normalizeIP(host)
	}

	raw := strings.TrimSpace(r.RemoteAddr)
	if net.ParseIP(raw) != nil {
		return normalizeIP(raw)
	}
	return ""
}

func normalizeIP(ip string) string {
	ip = strings.TrimSpace(ip)
	if strings.HasPrefix(ip, "::ffff:") {
		v4 := strings.TrimPrefix(ip, "::ffff:")
		if net.ParseIP(v4) != nil {
			return v4
		}
	}
	return ip
}

func (a *app) expectedProbeFamily(host string) string {
	switch host {
	case a.v4Host:
		return "ipv4"
	case a.v6Host:
		return "ipv6"
	default:
		return ""
	}
}

func ipFamily(ip string) string {
	parsed := net.ParseIP(strings.TrimSpace(ip))
	if parsed == nil {
		return ""
	}
	if parsed.To4() != nil {
		return "ipv4"
	}
	return "ipv6"
}

func isNonPublicIP(ip string) bool {
	parsed := net.ParseIP(strings.TrimSpace(ip))
	if parsed == nil {
		return false
	}
	return parsed.IsLoopback() || parsed.IsPrivate() || parsed.IsUnspecified()
}

func normalizeHost(host string) string {
	host = strings.TrimSpace(strings.ToLower(host))
	if strings.Contains(host, ":") {
		if h, _, err := net.SplitHostPort(host); err == nil {
			host = h
		}
	}
	return strings.TrimSuffix(host, ".")
}

func decodePathValue(v string) string {
	decoded, err := url.PathUnescape(v)
	if err != nil {
		return v
	}
	return strings.TrimSpace(decoded)
}

func isCLIRequest(r *http.Request) bool {
	ua := strings.ToLower(strings.TrimSpace(r.UserAgent()))
	for _, sig := range []string{"curl/", "wget/", "httpie/", "python-requests/", "powershell/", "go-http-client/"} {
		if strings.Contains(ua, sig) {
			return true
		}
	}
	return false
}

func normalizeASN(v string) string {
	parts := strings.Fields(strings.TrimSpace(v))
	if len(parts) == 0 {
		return ""
	}
	if strings.HasPrefix(strings.ToUpper(parts[0]), "AS") {
		return strings.ToUpper(parts[0])
	}
	return parts[0]
}

func preferredASName(rawASName, org, isp string) string {
	rawASName = normalizeEntityName(rawASName)
	org = normalizeEntityName(org)
	isp = normalizeEntityName(isp)

	switch {
	case rawASName != "":
		return rawASName
	case isp != "":
		return isp
	case org != "":
		return org
	default:
		return ""
	}
}

func normalizeEntityName(v string) string {
	return strings.Join(strings.Fields(strings.TrimSpace(v)), " ")
}

func toFloatPtr(v any) *float64 {
	s := strings.TrimSpace(toString(v))
	if s == "" {
		return nil
	}
	f, err := strconv.ParseFloat(s, 64)
	if err != nil {
		return nil
	}
	return &f
}

func toIntPtr(v any) *int {
	s := strings.TrimSpace(toString(v))
	if s == "" {
		return nil
	}
	i, err := strconv.Atoi(s)
	if err != nil {
		return nil
	}
	return &i
}

func toBoolPtr(v any) *bool {
	s := strings.TrimSpace(strings.ToLower(toString(v)))
	if s == "" {
		return nil
	}
	switch s {
	case "true", "1":
		v := true
		return &v
	case "false", "0":
		v := false
		return &v
	default:
		return nil
	}
}

func toString(v any) string {
	if v == nil {
		return ""
	}
	return fmt.Sprint(v)
}

func parseInt(v string, fallback int) int {
	n, err := strconv.Atoi(strings.TrimSpace(v))
	if err != nil || n <= 0 {
		return fallback
	}
	return n
}

func maxInt(a, b int) int {
	if a > b {
		return a
	}
	return b
}

func ensureDir(path string) error {
	if path == "" {
		return nil
	}
	return os.MkdirAll(path, 0o775)
}

func envOr(key, fallback string) string {
	if v := strings.TrimSpace(os.Getenv(key)); v != "" {
		return v
	}
	return fallback
}

func jsonResponse(w http.ResponseWriter, data any, status int) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	enc := json.NewEncoder(w)
	enc.SetEscapeHTML(false)
	enc.SetIndent("", "  ")
	_ = enc.Encode(data)
}

func textResponse(w http.ResponseWriter, text string, status int) {
	w.Header().Set("Content-Type", "text/plain; charset=utf-8")
	w.WriteHeader(status)
	_, _ = w.Write([]byte(strings.TrimSpace(text) + "\n"))
}

func htmlResponse(w http.ResponseWriter, body string, status int) {
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.WriteHeader(status)
	_, _ = w.Write([]byte(body))
}

func renderTemplate(tpl string, data any) string {
	t := template.Must(template.New("page").Parse(tpl))
	var sb strings.Builder
	_ = t.Execute(&sb, data)
	return sb.String()
}

const siteTemplate = `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{{if .LookupQuery}}{{.LookupQuery}} - IP 信息查询 - IPX.EE{{else}}IPX.EE - IPv4 / IPv6 / GeoIP Lookup API{{end}}</title>
  <meta name="description" content="{{if .LookupQuery}}{{.LookupQuery}} 的 GeoIP 查询结果。支持 IP 与域名查询。{{else}}IPX.EE 提供 IPv4、IPv6 与 GeoIP 查询。支持当前访问者 IP、指定 IP、指定域名查询，输出简洁直接的 JSON 结果。{{end}}">
  <meta name="robots" content="index,follow,max-image-preview:large">
  <meta name="application-name" content="IPX.EE">
  <meta name="theme-color" content="#0052d9">
  <link rel="canonical" href="https://{{.SiteHost}}{{if .LookupQuery}}/{{.LookupQuery}}{{else}}/{{end}}">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="IPX.EE">
  <meta property="og:title" content="{{if .LookupQuery}}{{.LookupQuery}} - IP 信息查询 - IPX.EE{{else}}IPX.EE - IPv4 / IPv6 / GeoIP Lookup API{{end}}">
  <meta property="og:description" content="{{if .LookupQuery}}{{.LookupQuery}} 的 GeoIP 查询结果。{{else}}快速查看 IPv4、IPv6 与 GeoIP 信息。支持当前访问者、指定 IP 和指定域名查询。{{end}}">
  <meta property="og:url" content="https://{{.SiteHost}}{{if .LookupQuery}}/{{.LookupQuery}}{{else}}/{{end}}">
  <meta name="twitter:card" content="summary">
  <meta name="twitter:title" content="{{if .LookupQuery}}{{.LookupQuery}} - IP 信息查询 - IPX.EE{{else}}IPX.EE - IPv4 / IPv6 / GeoIP Lookup API{{end}}">
  <meta name="twitter:description" content="{{if .LookupQuery}}{{.LookupQuery}} 的 GeoIP 查询结果。{{else}}快速查看 IPv4、IPv6 与 GeoIP 信息。支持当前访问者、指定 IP 和指定域名查询。{{end}}">
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' fill='%230052d9'/%3E%3C/svg%3E">
  <script defer src="https://tongji.giantaccel.com/script.js" data-website-id="e7e85005-1a72-4ce4-b9e1-691e91e82ccc"></script>
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "WebSite",
    "name": "IPX.EE",
    "url": "https://{{.SiteHost}}/",
    "description": "IPv4 / IPv6 / GeoIP Lookup API",
    "potentialAction": {
      "@type": "SearchAction",
      "target": "https://{{.APIHost}}/{search_term_string}",
      "query-input": "required name=search_term_string"
    }
  }
  </script>
  <style>
    :root {
      color-scheme: light;
      --bg: #f2f6fc;
      --bg-soft: #f7faff;
      --panel: #ffffff;
      --line: #d6dfec;
      --line-strong: #b9c9e0;
      --text: #16243a;
      --muted: #5d7088;
      --accent: #0052d9;
      --accent-soft: #eaf2ff;
      --ok: #16803c;
      --ok-bg: #edf8f1;
      --error: #c84b31;
      --error-bg: #fff1ed;
      --shadow: 0 14px 34px rgba(0, 56, 140, 0.08);
    }
    * { box-sizing: border-box; }
    html { background: var(--bg); }
    body {
      margin: 0;
      min-height: 100vh;
      background:
        linear-gradient(180deg, #f9fbff 0%, #f3f7fd 48%, #eef3f9 100%);
      color: var(--text);
      font-family: "Charter", "Iowan Old Style", "Palatino Linotype", Georgia, serif;
    }
    a { color: inherit; text-decoration: none; }
    code, pre, .value, .badge, .state, .section-title, .note-label {
      font-family: "IBM Plex Mono", "SFMono-Regular", Menlo, Consolas, monospace;
    }
    .wrap {
      max-width: 840px;
      margin: 0 auto;
      padding: 24px 16px 56px;
    }
    .hero,
    .table,
    .foot {
      background: var(--panel);
      border: 1px solid var(--line);
      box-shadow: var(--shadow);
    }
    .hero {
      border-radius: 0;
      border-top: 4px solid var(--accent);
      padding: 26px 24px;
    }
    .hero-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
    }
    .hero-copy {
      min-width: 0;
    }
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 8px 12px;
      border-radius: 0;
      border: 1px solid #bed3ff;
      background: var(--accent-soft);
      color: var(--accent);
      font-size: 12px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
    .badge::before {
      content: "";
      width: 8px;
      height: 8px;
      border-radius: 0;
      background: var(--accent);
    }
    h1 {
      margin: 18px 0 0;
      font-size: clamp(38px, 8vw, 56px);
      line-height: 0.98;
      letter-spacing: -0.06em;
    }
    .search-form {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 10px;
      width: min(100%, 440px);
      margin-top: 18px;
    }
    .search-input,
    .search-button {
      height: 48px;
      border-radius: 0;
      border: 1px solid var(--line-strong);
      font: inherit;
    }
    .search-input {
      width: 100%;
      padding: 0 14px;
      background: #fff;
      color: var(--text);
      outline: none;
    }
    .search-input:focus {
      border-color: var(--accent);
      box-shadow: inset 0 0 0 1px var(--accent);
    }
    .search-button {
      padding: 0 18px;
      background: var(--accent);
      color: #fff;
      cursor: pointer;
    }
    .hero-note {
      margin-top: 12px;
      color: var(--muted);
      font-size: 14px;
      line-height: 1.7;
      word-break: break-word;
    }
    .section {
      margin-top: 18px;
    }
    .section-title {
      margin: 0 0 10px;
      color: var(--accent);
      font-size: 12px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
    .table {
      border-radius: 0;
      overflow: hidden;
      border-top: 2px solid var(--accent);
    }
    .result-card,
    .list-row {
      padding: 20px 22px;
      background: #ffffff;
    }
    .result-card + .result-card,
    .list-row + .list-row {
      border-top: 1px solid var(--line);
    }
    .result-card {
      padding: 0;
    }
    .result-head {
      display: grid;
      grid-template-columns: 68px minmax(0, 1fr) auto;
      align-items: center;
      gap: 18px;
      padding: 22px;
      border-bottom: 1px solid var(--line);
    }
    .row-label {
      margin: 0;
      color: var(--accent);
      font-size: 13px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
    .state {
      padding: 6px 10px;
      border-radius: 0;
      border: 1px solid var(--line);
      background: var(--bg-soft);
      color: #7a8b9f;
      font-size: 11px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      margin-left: auto;
      justify-self: end;
    }
    .result-card.is-ready .state {
      color: var(--ok);
      background: var(--ok-bg);
      border-color: #b9ddc6;
    }
    .result-card.is-error .state {
      color: var(--error);
      background: var(--error-bg);
      border-color: #f0c9bf;
    }
    .value {
      margin: 0;
      font-size: clamp(22px, 5vw, 34px);
      line-height: 1.2;
      letter-spacing: -0.04em;
      color: var(--text);
      word-break: break-word;
    }
    .value.is-v6 {
      font-size: clamp(14px, 2.6vw, 24px);
      line-height: 1.15;
      letter-spacing: -0.05em;
      white-space: nowrap;
      word-break: normal;
      overflow-x: auto;
      overflow-y: hidden;
      scrollbar-width: none;
    }
    .value.is-v6::-webkit-scrollbar {
      display: none;
    }
    .meta {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 0;
      padding: 0 22px 10px;
      color: var(--muted);
      font-size: 14px;
      line-height: 1.7;
    }
    .geo-line {
      display: grid;
      grid-template-columns: 72px minmax(0, 1fr);
      align-items: center;
      gap: 14px;
      width: 100%;
      min-height: 24px;
      padding: 12px 0;
      border-top: 1px solid #edf2f8;
    }
    .geo-line:first-child {
      border-top: 0;
    }
    .geo-label {
      color: var(--text);
      font-size: 12px;
      letter-spacing: 0.06em;
      text-transform: uppercase;
    }
    .geo-value {
      min-width: 0;
      word-break: break-word;
    }
    .geo-country {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--text);
    }
    .geo-badges {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }
    .geo-badge {
      display: inline-flex;
      align-items: center;
      min-height: 28px;
      padding: 0 10px;
      border: 1px solid #eadb9c;
      background: #fff9e7;
      color: #7f6720;
      font-size: 12px;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      white-space: nowrap;
    }
    .geo-badge.is-true {
      border-color: #bed3ff;
      background: var(--accent-soft);
      color: var(--accent);
    }
    .geo-badge.is-risk {
      border-color: #f0c9bf;
      background: #fff3ef;
      color: #b55a45;
    }
    .geo-flag {
      width: 18px;
      height: 13px;
      display: inline-block;
      object-fit: cover;
      flex: 0 0 auto;
      vertical-align: middle;
    }
    .list-row strong {
      display: inline;
      margin-right: 10px;
      font-size: 14px;
      color: var(--text);
    }
    .list-row code {
      display: inline;
      margin-right: 10px;
      padding: 0;
      border-radius: 0;
      background: transparent;
      border: 0;
      color: var(--accent);
      font-size: 13px;
      word-break: break-word;
    }
    .list-row span {
      display: inline;
      color: var(--muted);
      font-size: 14px;
      line-height: 1.75;
    }
    .foot {
      margin-top: 18px;
      border-radius: 0;
      border-left: 4px solid var(--accent);
      padding: 16px 18px;
      color: var(--muted);
      font-size: 14px;
      line-height: 1.8;
    }
    .foot code {
      color: var(--text);
      font-size: 13px;
    }
    @media (max-width: 640px) {
      .wrap {
        padding: 16px 12px 36px;
      }
      .hero,
      .list-row {
        padding: 18px;
      }
      .hero-top {
        align-items: flex-start;
      }
      .search-form {
        grid-template-columns: 1fr;
        width: 100%;
      }
      .result-head {
        grid-template-columns: 1fr;
        gap: 12px;
        align-items: flex-start;
        padding: 18px;
      }
      .meta {
        padding: 0 18px 8px;
      }
      .state {
        margin-left: 0;
        justify-self: start;
      }
      .geo-line {
        grid-template-columns: 1fr;
        gap: 4px;
      }
    }
  </style>
</head>
<body>
  <main class="wrap">
    <section class="hero">
      <div class="hero-top">
        <div class="hero-copy">
          <a class="badge" href="/">ipx.ee</a>
          <h1>IP 信息查询</h1>
        </div>
      </div>
      <form class="search-form" id="search-form" action="/" method="get">
        <input class="search-input" id="search-input" name="q" type="search" placeholder="输入 IP 或域名，例如 8.8.8.8 或 google.com" value="{{.LookupQuery}}" autocomplete="off" spellcheck="false">
        <button class="search-button" type="submit">查询</button>
      </form>
      {{if .LookupQuery}}<div class="hero-note">结果页地址：<code>https://{{.SiteHost}}/{{.LookupQuery}}</code></div>{{end}}
    </section>

    <section class="section">
      <div class="section-title">{{if .LookupQuery}}查询结果{{else}}实时结果{{end}}</div>
      <div class="table">
        {{if .LookupQuery}}
        <article class="result-card is-loading" id="lookup-card">
          <div class="result-head">
            <div class="row-label">查询</div>
            <div class="value" id="lookup-ip">{{.LookupQuery}}</div>
            <span class="state" id="lookup-state">loading</span>
          </div>
          <div class="meta" id="lookup-meta">正在查询 https://{{.APIHost}}/{{.LookupQuery}}</div>
        </article>
        {{else}}
        <article class="result-card is-loading" id="v4-card">
          <div class="result-head">
            <div class="row-label">IPv4</div>
            <div class="value" id="v4-ip">加载中...</div>
            <span class="state" id="v4-state">loading</span>
          </div>
          <div class="meta" id="v4-meta">正在请求 https://{{.V4Host}}/</div>
        </article>

        <article class="result-card is-loading" id="v6-card">
          <div class="result-head">
            <div class="row-label">IPv6</div>
            <div class="value is-v6" id="v6-ip">加载中...</div>
            <span class="state" id="v6-state">loading</span>
          </div>
          <div class="meta" id="v6-meta">正在请求 https://{{.V6Host}}/</div>
        </article>
        {{end}}
      </div>
    </section>

    <section class="section">
      <div class="section-title">补充说明</div>
      <div class="table">
        <div class="list-row">
          <strong>主站</strong>
          <code>curl {{.SiteHost}}</code>
          <span>直接返回访问 IP。</span>
        </div>
        <div class="list-row">
          <strong>当前访问者 GeoIP</strong>
          <code>GET https://{{.APIHost}}/ip</code>
          <span>返回当前访问者的 GeoIP JSON。</span>
        </div>
        <div class="list-row">
          <strong>指定 IP</strong>
          <code>GET https://{{.APIHost}}/24.48.0.1</code>
          <span>直接按指定 IP 查询。</span>
        </div>
        <div class="list-row">
          <strong>指定域名</strong>
          <code>GET https://{{.APIHost}}/example.com</code>
          <span>域名会先解析，再返回 GeoIP。</span>
        </div>
        <div class="list-row">
          <strong>IPv4 探测</strong>
          <code>curl -4 {{.SiteHost}}</code>
          <code>curl {{.V4Host}}</code>
          <span>两种方式都只看当前访问者的 IPv4。</span>
        </div>
        <div class="list-row">
          <strong>IPv6 探测</strong>
          <code>curl -6 {{.SiteHost}}</code>
          <code>curl {{.V6Host}}</code>
          <span>两种方式都只看当前访问者的 IPv6。</span>
        </div>
      </div>
    </section>

    <div class="foot">如果你只想把它当工具 API 用，直接请求 <code>https://{{.APIHost}}/ip</code> 和 <code>https://{{.APIHost}}/{ip-or-domain}</code> 就够了。</div>
  </main>

  <script>
    (function () {
      const apiHost = "https://{{.APIHost}}";
      const lookupQuery = "{{.LookupQuery}}";
      const v6Host = "{{.V6Host}}";
      const searchForm = document.getElementById("search-form");
      const searchInput = document.getElementById("search-input");
      const lookupCard = document.getElementById("lookup-card");
      const lookupState = document.getElementById("lookup-state");
      const lookupIp = document.getElementById("lookup-ip");
      const lookupMeta = document.getElementById("lookup-meta");
      const v4Card = document.getElementById("v4-card");
      const v6Card = document.getElementById("v6-card");
      const v4State = document.getElementById("v4-state");
      const v6State = document.getElementById("v6-state");
      const v4Ip = document.getElementById("v4-ip");
      const v4Meta = document.getElementById("v4-meta");
      const v6Ip = document.getElementById("v6-ip");
      const v6Meta = document.getElementById("v6-meta");

      function setState(card, stateNode, state) {
        if (!card || !stateNode) return;
        card.classList.remove("is-loading", "is-ready", "is-error");
        card.classList.add("is-" + state);
        if (state === "ready") {
          stateNode.textContent = "ok";
          return;
        }
        stateNode.textContent = state;
      }

      function clearNode(node) {
        while (node.firstChild) {
          node.removeChild(node.firstChild);
        }
      }

      function appendPlainMeta(node, text) {
        clearNode(node);
        node.textContent = text;
      }

      function renderMeta(node, data, opts) {
        if (!node) return;
        if (!data || data.error) {
          appendPlainMeta(node, data && data.error ? data.error : "查询失败");
          return;
        }

        const options = opts || {};
        const lookup = String(data.lookup || "").trim();
        const network = data.network || {};
        const flags = data.flags || {};
        const location = data.location || {};
        const assets = data.assets || {};

        function firstValue() {
          for (let i = 0; i < arguments.length; i += 1) {
            const value = arguments[i];
            if (value !== undefined && value !== null && value !== "") {
              return value;
            }
          }
          return "";
        }

        const country = String(firstValue(location.country, data.country)).trim();
        const countryCode = String(firstValue(location.country_code, data.country_code)).trim().toLowerCase();
        const flagURL = String(firstValue(assets.flag, data.flag)).trim();
        const provinceCity = [firstValue(location.province, data.province), firstValue(location.city, data.city)].filter(Boolean).join(" / ");
        const latitude = String(firstValue(location.latitude, data.latitude)).trim();
        const longitude = String(firstValue(location.longitude, data.longitude)).trim();
        const coordinates = latitude && longitude ? latitude + ", " + longitude : "";
        const asnValue = String(firstValue(network.asn, data.asn)).trim();
        const rawASName = String(firstValue(network.as_name, data.as_name)).trim();
        const rawISP = String(firstValue(network.isp, data.isp)).trim();
        const rawOrg = String(firstValue(network.org, data.org)).trim();

        function normalizeName(value) {
          return String(value || "").trim().replace(/\s+/g, " ");
        }
        const isp = normalizeName(rawISP);
        const org = normalizeName(rawOrg);
        const displayName = isp || org || normalizeName(rawASName);

        clearNode(node);

        function appendLine(label, value, formatter) {
          if (!value) return;
          const row = document.createElement("div");
          row.className = "geo-line";

          const labelNode = document.createElement("strong");
          labelNode.className = "geo-label";
          labelNode.textContent = label;
          row.appendChild(labelNode);

          const valueNode = document.createElement("span");
          valueNode.className = "geo-value";

          if (typeof formatter === "function") {
            formatter(valueNode, value);
          } else {
            valueNode.textContent = value;
          }

          row.appendChild(valueNode);
          node.appendChild(row);
        }

        if (options.showLookup && lookup) {
          appendLine("查询", lookup);
        }
        appendLine("ASN", asnValue);
        appendLine("名称", displayName);
        appendLine("ISP", isp && isp !== displayName ? isp : "");
        appendLine("组织", org && org !== displayName && org !== isp ? org : "");
        appendLine("国家", country, function (valueNode, value) {
          const countryWrap = document.createElement("span");
          countryWrap.className = "geo-country";
          if (flagURL || countryCode) {
            const flag = document.createElement("img");
            flag.className = "geo-flag";
            flag.src = flagURL || ("https://flagcdn.io/" + countryCode + ".svg");
            flag.alt = value;
            flag.loading = "lazy";
            countryWrap.appendChild(flag);
          }

          countryWrap.appendChild(document.createTextNode(value));
          valueNode.appendChild(countryWrap);
        });
        appendLine("省市", provinceCity);
        appendLine("经纬度", coordinates);
        appendLine("属性", "badges", function (valueNode) {
          const badges = document.createElement("span");
          badges.className = "geo-badges";

          function appendBadge(label, value, danger) {
            const badge = document.createElement("span");
            badge.className = "geo-badge";
            if (value === true) {
              badge.classList.add(danger ? "is-risk" : "is-true");
            }
            badge.textContent = label + ": " + (value === true ? "YES" : "NO");
            badges.appendChild(badge);
          }

          appendBadge("HOSTING", flags.is_hosting === true || data.is_hosting === true, false);
          appendBadge("MOBILE", flags.is_mobile === true || data.is_mobile === true, false);
          appendBadge("PROXY", flags.is_proxy === true || data.is_proxy === true, true);
          valueNode.appendChild(badges);
        });

        if (!node.firstChild) {
          node.textContent = "无更多地理信息";
        }
      }

      async function fetchJSON(url) {
        const res = await fetch(url, { headers: { Accept: "application/json" }, cache: "no-store" });
        return res.json();
      }

      async function fetchText(url) {
        const res = await fetch(url, { cache: "no-store" });
        return (await res.text()).trim();
      }

      function normalizeSearchInput(value) {
        value = String(value || "").trim();
        value = value.replace(/^[a-z]+:\/\//i, "");
        value = value.replace(/^\/+/, "");
        value = value.split(/[/?#]/)[0];
        value = value.replace(/\/+$/, "");
        if (value.startsWith("[") && value.endsWith("]")) {
          value = value.slice(1, -1);
        }
        return value.trim();
      }

      async function loadLookup(query, card, stateNode, ipNode, metaNode) {
        try {
          const geo = await fetchJSON(apiHost + "/" + encodeURIComponent(query));
          ipNode.textContent = geo && geo.ip ? geo.ip : query;
          renderMeta(metaNode, geo, { showLookup: true });
          setState(card, stateNode, geo && !geo.error ? "ready" : "error");
        } catch (err) {
          ipNode.textContent = query || "不可用";
          appendPlainMeta(metaNode, "查询失败");
          setState(card, stateNode, "error");
        }
      }

      async function loadStack(host, card, stateNode, ipNode, metaNode, unavailableText) {
        try {
          const geo = await fetchJSON("https://" + host + "/?format=json");
          const ip = geo && geo.ip ? String(geo.ip).trim() : "";
          ipNode.textContent = ip || "不可用";
          if (!ip) {
            appendPlainMeta(metaNode, unavailableText || (geo && geo.error ? geo.error : "未返回 IP"));
            setState(card, stateNode, "error");
            return;
          }
          renderMeta(metaNode, geo);
          setState(card, stateNode, geo && !geo.error ? "ready" : "error");
        } catch (err) {
          ipNode.textContent = "不可用";
          appendPlainMeta(metaNode, unavailableText || "访问失败");
          setState(card, stateNode, "error");
        }
      }

      if (searchForm && searchInput) {
        searchForm.addEventListener("submit", function (event) {
          const query = normalizeSearchInput(searchInput.value);
          if (!query) {
            event.preventDefault();
            searchInput.focus();
            return;
          }
          event.preventDefault();
          window.location.href = "/" + encodeURIComponent(query);
        });
      }

      if (lookupQuery && lookupCard && lookupState && lookupIp && lookupMeta) {
        loadLookup(lookupQuery, lookupCard, lookupState, lookupIp, lookupMeta);
        return;
      }

      loadStack("{{.V4Host}}", v4Card, v4State, v4Ip, v4Meta);
      loadStack(v6Host, v6Card, v6State, v6Ip, v6Meta, "不支持 IPv6");
    })();
  </script>
</body>
</html>`
