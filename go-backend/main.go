package main

import (
	"encoding/json"
	"errors"
	"fmt"
	"html/template"
	"log"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/lionsoul2014/ip2region/binding/golang/service"
	"github.com/lionsoul2014/ip2region/binding/golang/xdb"
)

type app struct {
	ip2r       *service.Ip2Region
	port       string
	projectDir string
	tz         *time.Location
}

type footerMeta struct {
	UpdatedAt    string
	NextUpdateAt string
}

type pageData struct {
	Lang           string
	Title          string
	Description    string
	ActiveNav      string
	Year           int
	FooterMeta     footerMeta
	FieldsJSON     template.JS
	InitialGeoJSON template.JS
	InitialQueryIP template.JS
	QueryIP        string
	StatusText     string
	QueryHeading   string
}

func main() {
	port := envOr("PORT", "18080")
	projectDir := envOr("PROJECT_ROOT", "..")
	projectDir, _ = filepath.Abs(projectDir)

	v4DB := envOr("IP2R_V4_DB", filepath.Join(projectDir, "data", "high_full.xdb"))
	v6DB := envOr("IP2R_V6_DB", filepath.Join(projectDir, "data", "high_full_v6.xdb"))
	cachePolicy := parseCachePolicy(envOr("IP2R_CACHE_POLICY", "vectorIndex"))
	poolSize := parseInt(envOr("IP2R_SEARCHERS", "20"), 20)
	verify := parseBool(envOr("IP2R_VERIFY_DB", "true"), true)

	tzName := envOr("APP_TIMEZONE", "Asia/Tashkent")
	tz, err := time.LoadLocation(tzName)
	if err != nil {
		tz = time.FixedZone("UTC+5", 5*3600)
	}

	if verify {
		if err := xdb.VerifyFromFile(v4DB); err != nil {
			log.Fatalf("verify v4 xdb failed: %v", err)
		}
		if err := xdb.VerifyFromFile(v6DB); err != nil {
			log.Fatalf("verify v6 xdb failed: %v", err)
		}
	}

	v4Config, err := service.NewV4Config(cachePolicy, v4DB, poolSize)
	if err != nil {
		log.Fatalf("create v4 config failed: %v", err)
	}
	v6Config, err := service.NewV6Config(cachePolicy, v6DB, poolSize)
	if err != nil {
		log.Fatalf("create v6 config failed: %v", err)
	}

	ip2r, err := service.NewIp2Region(v4Config, v6Config)
	if err != nil {
		log.Fatalf("create ip2region service failed: %v", err)
	}
	defer ip2r.Close()

	a := &app{ip2r: ip2r, port: port, projectDir: projectDir, tz: tz}

	mux := http.NewServeMux()

	// API routes
	mux.HandleFunc("/ip", a.handleIP)
	mux.HandleFunc("/jsonip", a.handleJSONIP)
	mux.HandleFunc("/geoip", a.handleGeoIP)
	mux.HandleFunc("/geoip/", a.handleGeoIPByIP)
	mux.HandleFunc("/health", a.handleHealth)

	// Page routes
	mux.HandleFunc("/api", a.handleAPIPage)
	mux.HandleFunc("/about", a.handleAboutPage)
	mux.HandleFunc("/contact", a.handleContactPage)
	mux.HandleFunc("/ip/", a.handleHomeByIP)
	mux.HandleFunc("/", a.handleHome)

	// Static files
	assetsDir := filepath.Join(projectDir, "assets")
	if st, err := os.Stat(assetsDir); err == nil && st.IsDir() {
		mux.Handle("/assets/", http.StripPrefix("/assets/", http.FileServer(http.Dir(assetsDir))))
	}

	for _, p := range []string{
		"/favicon.ico",
		"/favicon-16x16.png",
		"/favicon-32x32.png",
		"/apple-touch-icon.png",
		"/android-chrome-192x192.png",
		"/android-chrome-512x512.png",
		"/site.webmanifest",
	} {
		mux.HandleFunc(p, a.handleStaticFile)
	}

	srv := &http.Server{
		Addr:              ":" + port,
		Handler:           withCORS(mux),
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       15 * time.Second,
		WriteTimeout:      25 * time.Second,
		IdleTimeout:       60 * time.Second,
	}

	log.Printf("go server listening on :%s", port)
	log.Printf("xdb v4=%s v6=%s cache=%s pool=%d", v4DB, v6DB, envOr("IP2R_CACHE_POLICY", "vectorIndex"), poolSize)
	if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		log.Fatal(err)
	}
}

func withCORS(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Methods", "GET, OPTIONS")
		w.Header().Set("Access-Control-Allow-Headers", "Content-Type, X-Requested-With")
		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusNoContent)
			return
		}
		next.ServeHTTP(w, r)
	})
}

func (a *app) handleStaticFile(w http.ResponseWriter, r *http.Request) {
	cleanPath := filepath.Clean(strings.TrimPrefix(r.URL.Path, "/"))
	if cleanPath == "." || strings.Contains(cleanPath, "..") {
		http.NotFound(w, r)
		return
	}
	full := filepath.Join(a.projectDir, cleanPath)
	if st, err := os.Stat(full); err != nil || st.IsDir() {
		http.NotFound(w, r)
		return
	}
	http.ServeFile(w, r, full)
}

func getClientIP(r *http.Request) string {
	if xff := strings.TrimSpace(r.Header.Get("X-Forwarded-For")); xff != "" {
		parts := strings.Split(xff, ",")
		ip := strings.TrimSpace(parts[0])
		if net.ParseIP(ip) != nil {
			return normalizeIP(ip)
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
	if net.ParseIP(strings.TrimSpace(r.RemoteAddr)) != nil {
		return normalizeIP(strings.TrimSpace(r.RemoteAddr))
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

func ipVersion(ip string) int {
	parsed := net.ParseIP(strings.TrimSpace(ip))
	if parsed == nil {
		return 0
	}
	if parsed.To4() != nil {
		return 4
	}
	return 6
}

func isCLILikeRequest(r *http.Request) bool {
	ua := strings.ToLower(strings.TrimSpace(r.UserAgent()))
	if ua == "" {
		return false
	}
	sigs := []string{"curl/", "wget/", "httpie/", "python-requests/", "powershell/", "go-http-client/"}
	for _, s := range sigs {
		if strings.Contains(ua, s) {
			return true
		}
	}
	return false
}

func buildRootCliIPSummary(ip string) string {
	return strings.TrimSpace(ip)
}

func (a *app) handleIP(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	ip := getClientIP(r)
	w.Header().Set("Content-Type", "text/plain; charset=utf-8")
	_, _ = w.Write([]byte(ip + "\n"))
}

func (a *app) handleJSONIP(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	jsonResponse(w, map[string]any{"ip": getClientIP(r)}, http.StatusOK)
}

func (a *app) handleGeoIP(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	ip := getClientIP(r)
	if net.ParseIP(ip) == nil {
		jsonResponse(w, map[string]any{"error": "Invalid IP address", "input": ip}, http.StatusBadRequest)
		return
	}

	data, status := a.queryGeoIP(ip)
	if status != http.StatusOK {
		jsonResponse(w, data, status)
		return
	}

	if field := strings.TrimSpace(r.URL.Query().Get("field")); field != "" {
		filtered, err := filterGeoipByField(data, field)
		if err != nil {
			jsonResponse(w, map[string]any{"error": "Invalid field", "field": field, "available_fields": queryableFields()}, http.StatusBadRequest)
			return
		}
		jsonResponse(w, filtered, http.StatusOK)
		return
	}
	jsonResponse(w, data, http.StatusOK)
}

func (a *app) handleGeoIPByIP(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	ip := strings.TrimSpace(strings.TrimPrefix(r.URL.Path, "/geoip/"))
	if ip == "" || net.ParseIP(ip) == nil {
		jsonResponse(w, map[string]any{"error": "Invalid IP address", "input": ip}, http.StatusBadRequest)
		return
	}

	data, status := a.queryGeoIP(ip)
	if status != http.StatusOK {
		jsonResponse(w, data, status)
		return
	}

	if field := strings.TrimSpace(r.URL.Query().Get("field")); field != "" {
		filtered, err := filterGeoipByField(data, field)
		if err != nil {
			jsonResponse(w, map[string]any{"error": "Invalid field", "field": field, "available_fields": queryableFields()}, http.StatusBadRequest)
			return
		}
		jsonResponse(w, filtered, http.StatusOK)
		return
	}
	jsonResponse(w, data, http.StatusOK)
}

func (a *app) queryGeoIP(ip string) (map[string]any, int) {
	region, err := a.ip2r.SearchByStr(ip)
	if err != nil {
		return map[string]any{"error": "GeoIP lookup failed", "detail": err.Error(), "input": ip}, http.StatusInternalServerError
	}
	if strings.TrimSpace(region) == "" {
		return map[string]any{"ip": ip}, http.StatusOK
	}
	return parseRegion(ip, region), http.StatusOK
}

func parseRegion(ip, region string) map[string]any {
	parts := strings.Split(region, "|")
	for len(parts) < 17 {
		parts = append(parts, "")
	}
	clean := func(v string) string {
		v = strings.TrimSpace(v)
		if v == "" || v == "0" {
			return ""
		}
		return v
	}
	toFloat := func(v string) any {
		v = strings.TrimSpace(v)
		if v == "" || v == "0" {
			return nil
		}
		f, err := strconv.ParseFloat(v, 64)
		if err != nil {
			return nil
		}
		return f
	}

	return map[string]any{
		"ip":           ip,
		"continent":    clean(parts[0]),
		"country":      clean(parts[1]),
		"province":     clean(parts[2]),
		"city":         clean(parts[3]),
		"district":     clean(parts[4]),
		"isp":          clean(parts[5]),
		"longitude":    toFloat(parts[6]),
		"latitude":     toFloat(parts[7]),
		"area_code":    clean(parts[8]),
		"phone_code":   clean(parts[9]),
		"zip_code":     clean(parts[10]),
		"timezone":     clean(parts[11]),
		"currency":     clean(parts[12]),
		"asn":          clean(parts[13]),
		"country_code": clean(parts[16]),
	}
}

func queryableFields() []string {
	return []string{
		"ip", "continent", "country", "country_code", "province", "city", "district", "isp", "asn",
		"longitude", "latitude", "timezone", "currency", "area_code", "phone_code", "zip_code",
	}
}

func filterGeoipByField(data map[string]any, field string) (map[string]any, error) {
	field = strings.TrimSpace(field)
	if field == "" {
		return data, nil
	}
	requested := make([]string, 0, 8)
	seen := map[string]bool{}
	for _, raw := range strings.Split(field, ",") {
		f := strings.TrimSpace(raw)
		if f == "" || seen[f] {
			continue
		}
		seen[f] = true
		requested = append(requested, f)
	}
	if len(requested) == 0 {
		return data, nil
	}
	allowed := map[string]bool{}
	for _, f := range queryableFields() {
		allowed[f] = true
	}
	for _, f := range requested {
		if !allowed[f] {
			return nil, errors.New("invalid field")
		}
	}
	if len(requested) == 1 && requested[0] == "ip" {
		return map[string]any{"ip": toString(data["ip"])}, nil
	}
	resp := map[string]any{"ip": toString(data["ip"])}
	for _, f := range requested {
		if f == "ip" {
			continue
		}
		resp[f] = data[f]
	}
	return resp, nil
}

func toString(v any) string {
	if v == nil {
		return ""
	}
	return fmt.Sprint(v)
}

func (a *app) handleHealth(w http.ResponseWriter, r *http.Request) {
	jsonResponse(w, map[string]any{"ok": true}, http.StatusOK)
}

func (a *app) handleHome(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path != "/" {
		http.NotFound(w, r)
		return
	}
	clientIP := getClientIP(r)
	if isCLILikeRequest(r) {
		textResponse(w, buildRootCliIPSummary(clientIP), http.StatusOK)
		return
	}
	a.renderHomePage(w, "", "home", clientIP)
}

func (a *app) handleHomeByIP(w http.ResponseWriter, r *http.Request) {
	queryIP := strings.TrimSpace(strings.TrimPrefix(r.URL.Path, "/ip/"))
	if queryIP == "" {
		a.renderHomePage(w, "", "home", getClientIP(r))
		return
	}
	decoded := queryIP
	if d, err := urlPathUnescape(decoded); err == nil {
		decoded = d
	}
	if net.ParseIP(decoded) == nil {
		a.renderHomePage(w, "", "home", getClientIP(r))
		return
	}
	a.renderHomePage(w, decoded, "home", getClientIP(r))
}

func (a *app) handleAPIPage(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path != "/api" {
		http.NotFound(w, r)
		return
	}
	pd := a.basePageData("api", "zh-CN", "API Documentation - ip.Bluecdn.com", "ip.bluecdn.com API 文档")
	htmlResponse(w, renderAPIPage(pd), http.StatusOK)
}

func (a *app) handleAboutPage(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path != "/about" {
		http.NotFound(w, r)
		return
	}
	pd := a.basePageData("about", "zh-CN", "About - ip.Bluecdn.com", "关于 ip.bluecdn.com")
	htmlResponse(w, renderAboutPage(pd), http.StatusOK)
}

func (a *app) handleContactPage(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path != "/contact" {
		http.NotFound(w, r)
		return
	}
	pd := a.basePageData("contact", "zh-CN", "Contact - ip.Bluecdn.com", "联系 ip.bluecdn.com")
	htmlResponse(w, renderContactPage(pd), http.StatusOK)
}

func (a *app) renderHomePage(w http.ResponseWriter, queryIP, activeNav, clientIP string) {
	initialIP := clientIP
	if queryIP != "" {
		initialIP = queryIP
	}
	initialData, _ := a.queryGeoIP(initialIP)
	if initialData == nil {
		initialData = map[string]any{"ip": initialIP}
	}

	fields := []map[string]string{
		{"key": "ip", "label": "IP 地址"},
		{"key": "asn", "label": "ASN"},
		{"key": "isp", "label": "ISP"},
		{"key": "country", "label": "国家/代码"},
		{"key": "province", "label": "省"},
		{"key": "city", "label": "市"},
		{"key": "district", "label": "区"},
		{"key": "longitude", "label": "经度"},
		{"key": "latitude", "label": "纬度"},
		{"key": "timezone", "label": "时区"},
		{"key": "currency", "label": "货币"},
		{"key": "continent", "label": "大洲"},
		{"key": "area_code", "label": "行政区划代码"},
		{"key": "phone_code", "label": "电话区号"},
		{"key": "zip_code", "label": "邮政编码"},
	}

	fieldsJSON := mustJSON(fields)
	initialGeoJSON := mustJSON(initialData)
	initialQueryJSON := "null"
	status := "正在识别本机 IPv4 / IPv6 ..."
	if queryIP != "" {
		initialQueryJSON = mustJSONString(queryIP)
		status = "查询成功：/geoip/" + queryIP
	}

	pd := a.basePageData(activeNav, "zh-CN", "IP Address Lookup / IPv4 / IPv6  - ip.Bluecdn.com", "IPv4 Address Lookup, IPv6 Address Lookup")
	pd.FieldsJSON = template.JS(fieldsJSON)
	pd.InitialGeoJSON = template.JS(initialGeoJSON)
	pd.InitialQueryIP = template.JS(initialQueryJSON)
	pd.QueryIP = queryIP
	pd.StatusText = status
	pd.QueryHeading = "IP 地理查询"

	htmlResponse(w, renderHomePage(pd), http.StatusOK)
}

func (a *app) basePageData(activeNav, lang, title, desc string) pageData {
	now := time.Now().In(a.tz)
	meta := a.loadFooterMeta(now)
	return pageData{
		Lang:        lang,
		Title:       title,
		Description: desc,
		ActiveNav:   activeNav,
		Year:        now.Year(),
		FooterMeta:  meta,
	}
}

func (a *app) loadFooterMeta(now time.Time) footerMeta {
	updatedAt := "—"
	nextAt := nextMonthlyAt(now, 2, 0, 0).Format("2006-01-02 15:04:05")

	stateFile := filepath.Join(a.projectDir, "data", "xdb_update_state.json")
	raw, err := os.ReadFile(stateFile)
	if err == nil {
		var st map[string]any
		if json.Unmarshal(raw, &st) == nil {
			if v := strings.TrimSpace(toString(st["last_success_at"])); v != "" {
				updatedAt = parseTimeLike(v, a.tz)
			}
			if v := strings.TrimSpace(toString(st["next_update_at"])); v != "" {
				nextAt = parseTimeLike(v, a.tz)
			}
		}
	}

	if updatedAt == "—" {
		cacheFile := filepath.Join(a.projectDir, "data", "xdb_meta_cache.json")
		if raw2, err2 := os.ReadFile(cacheFile); err2 == nil {
			var meta map[string]any
			if json.Unmarshal(raw2, &meta) == nil {
				if v := strings.TrimSpace(toString(meta["updated_at"])); v != "" {
					updatedAt = parseTimeLike(v, a.tz)
				}
			}
		}
	}

	if updatedAt == "—" {
		v4 := fileMTime(filepath.Join(a.projectDir, "data", "high_full.xdb"))
		v6 := fileMTime(filepath.Join(a.projectDir, "data", "high_full_v6.xdb"))
		last := v4
		if v6.After(last) {
			last = v6
		}
		if !last.IsZero() {
			updatedAt = last.In(a.tz).Format("2006-01-02 15:04:05")
		}
	}

	return footerMeta{UpdatedAt: updatedAt, NextUpdateAt: nextAt}
}

func fileMTime(path string) time.Time {
	st, err := os.Stat(path)
	if err != nil {
		return time.Time{}
	}
	return st.ModTime()
}

func nextMonthlyAt(now time.Time, day, hour, minute int) time.Time {
	if day < 1 {
		day = 1
	}
	if day > 28 {
		day = 28
	}
	this := time.Date(now.Year(), now.Month(), day, hour, minute, 0, 0, now.Location())
	if now.Before(this) {
		return this
	}
	n := now.AddDate(0, 1, 0)
	return time.Date(n.Year(), n.Month(), day, hour, minute, 0, 0, now.Location())
}

func parseTimeLike(v string, loc *time.Location) string {
	v = strings.TrimSpace(v)
	if v == "" {
		return "—"
	}
	if _, err := strconv.ParseInt(v, 10, 64); err == nil {
		ts, _ := strconv.ParseInt(v, 10, 64)
		if ts > 0 {
			return time.Unix(ts, 0).In(loc).Format("2006-01-02 15:04:05")
		}
	}
	if t, err := time.ParseInLocation("2006-01-02 15:04:05", v, loc); err == nil {
		return t.Format("2006-01-02 15:04:05")
	}
	if t, err := time.Parse(time.RFC3339, v); err == nil {
		return t.In(loc).Format("2006-01-02 15:04:05")
	}
	return v
}

func mustJSON(v any) string {
	b, _ := json.Marshal(v)
	return string(b)
}

func mustJSONString(v string) string {
	b, _ := json.Marshal(v)
	return string(b)
}

func jsonResponse(w http.ResponseWriter, data map[string]any, status int) {
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
	_, _ = w.Write([]byte(text + "\n"))
}

func htmlResponse(w http.ResponseWriter, html string, status int) {
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.WriteHeader(status)
	_, _ = w.Write([]byte(html))
}

func renderTemplate(tpl string, data any) string {
	t := template.Must(template.New("page").Parse(tpl))
	var sb strings.Builder
	_ = t.Execute(&sb, data)
	return sb.String()
}

func renderHeader(active string) string {
	cls := func(name string) string {
		if active == name {
			return "nav-link is-active"
		}
		return "nav-link"
	}
	return renderTemplate(headerTemplate, map[string]any{
		"HomeClass":    cls("home"),
		"AboutClass":   cls("about"),
		"APIClass":     cls("api"),
		"ContactClass": cls("contact"),
	})
}

func renderFooter(pd pageData) string {
	return renderTemplate(footerTemplate, pd)
}

func renderHomePage(pd pageData) string {
	header := renderHeader(pd.ActiveNav)
	footer := renderFooter(pd)
	return renderTemplate(homeTemplate, map[string]any{
		"P":      pd,
		"Header": template.HTML(header),
		"Footer": template.HTML(footer),
	})
}

func renderAPIPage(pd pageData) string {
	header := renderHeader(pd.ActiveNav)
	footer := renderFooter(pd)
	return renderTemplate(apiTemplate, map[string]any{"P": pd, "Header": template.HTML(header), "Footer": template.HTML(footer)})
}

func renderAboutPage(pd pageData) string {
	header := renderHeader(pd.ActiveNav)
	footer := renderFooter(pd)
	return renderTemplate(aboutTemplate, map[string]any{"P": pd, "Header": template.HTML(header), "Footer": template.HTML(footer)})
}

func renderContactPage(pd pageData) string {
	header := renderHeader(pd.ActiveNav)
	footer := renderFooter(pd)
	return renderTemplate(contactTemplate, map[string]any{"P": pd, "Header": template.HTML(header), "Footer": template.HTML(footer)})
}

func urlPathUnescape(v string) (string, error) {
	replacer := strings.NewReplacer("%2F", "/", "%2f", "/")
	v = replacer.Replace(v)
	return strings.ReplaceAll(v, "%3A", ":"), nil
}

func parseCachePolicy(policy string) int {
	switch strings.ToLower(strings.TrimSpace(policy)) {
	case "file", "nocache", "none":
		return service.NoCache
	case "content", "buffer", "buffercache":
		return service.BufferCache
	default:
		return service.VIndexCache
	}
}

func parseInt(s string, fallback int) int {
	n, err := strconv.Atoi(strings.TrimSpace(s))
	if err != nil || n <= 0 {
		return fallback
	}
	return n
}

func parseBool(s string, fallback bool) bool {
	s = strings.TrimSpace(strings.ToLower(s))
	if s == "" {
		return fallback
	}
	return s == "1" || s == "true" || s == "yes" || s == "y" || s == "on"
}

func envOr(key, fallback string) string {
	if v := strings.TrimSpace(os.Getenv(key)); v != "" {
		return v
	}
	return fallback
}

const commonHead = `<link rel="stylesheet" href="https://icons.bluecdn.com/fontawesome-pro/css/all.min.css">
<link rel="stylesheet" href="https://flagcdn.io/css/flag-icons.min.css" />
<link rel="preconnect" href="https://fonts.bluecdn.com">
<link href="https://fonts.bluecdn.com/css2?family=Cairo+Play:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://static.bluecdn.com/npm/tailwindcss@4.2.1/index.min.css">
<link rel="stylesheet" href="/assets/main.css">`

const headerTemplate = `<nav class="top-nav">
  <div class="top-nav-inner">
    <div>
      <a href="/" class="brand-link" aria-label="ip.bluecdn.com">
        <svg t="1772241833459" class="brand-logo" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="13684" width="24" height="24" fill="currentColor" aria-hidden="true">
          <path d="M582.3 918H182.1c-41.2-0.3-74.5-35.8-74.5-79.5V186.9c0-43.9 33.6-79.4 74.9-79.4h662.1c41.2 0.3 74.5 35.7 74.5 79.4v434c0 7.7-5.9 14-13.2 14-7.3 0-13.2-6.3-13.2-14v-434c-0.1-28.3-21.8-51.3-48.5-51.3H182.1c-26.6 0.3-48 23.2-48.1 51.3v651.7c0 28.4 21.7 51.4 48.5 51.4h399.7c7.3 0 13.2 6.3 13.2 14 0.1 7.8-5.8 14-13.1 14z"></path>
          <path d="M582.3 927.4H182.1c-46.1-0.3-83.3-40-83.3-88.8V186.9c0.1-49 37.6-88.7 83.8-88.8h662.1c46 0.3 83.2 39.9 83.3 88.8v434c0 12.9-9.9 23.4-22 23.4s-22-10.5-22-23.4v-434c0-23.2-17.8-42-39.7-42H182.1c-21.7 0.3-39.2 19-39.2 42v651.7c0 23.2 17.8 42 39.7 42.1h399.7c12.2 0 22 10.5 22 23.4 0 12.8-9.8 23.3-22 23.3zM182.1 117c-36.2 0.3-65.5 31.4-65.7 69.9v651.7c0 38.7 29.6 70.1 66.1 70.1h399.7c2.4 0 4.4-2.1 4.4-4.7s-2-4.7-4.4-4.7H182.1c-31.5-0.3-56.8-27.4-56.9-60.8V186.9c0-33.5 25.7-60.7 57.3-60.7h662.1c31.4 0.3 56.8 27.3 56.9 60.7v434c0 2.6 2 4.7 4.4 4.7 2.4 0 4.4-2.1 4.4-4.7v-434c0-38.7-29.6-70-66.1-70l-662.1 0.1z"></path>
          <path d="M729.1 869.6c-63.3 0-114.6-54.4-114.6-121.6s51.3-121.6 114.6-121.6S843.7 680.9 843.7 748c0 67.2-51.3 121.6-114.6 121.6z m0-214.4c-48.7 0-88.2 41.9-88.2 93.5s39.5 93.5 88.2 93.5 88.2-41.9 88.2-93.5c0.1-51.6-39.3-93.6-88-93.7h-0.2v0.2z"></path>
          <path d="M876.4 927.7c-3.7 0-7.3-1.7-9.8-4.6l-76.5-88.9c-5.5-5.1-6-14-1.2-19.8 4.8-5.8 13.2-6.4 18.7-1.3 0.8 0.7 1.4 1.5 2 2.4l76.5 88.8c4.9 5.7 4.6 14.6-0.8 19.8-2.4 2.4-5.6 3.6-8.9 3.6zM423.6 340.7v315.6h-82.7V340.7h82.7z m146.8 315.7h-80.7V340.7h117.9C712.3 339.6 764 372 762.7 438c-2.8 61.3-46.9 93.8-132.2 97.3h-60l-0.1 121.1z m0-262.8v88.8h24.8c55.1 4.6 82-10.2 80.7-44.3 0-30.8-26.4-45.5-80.7-44.4l-24.8-0.1z"></path>
        </svg>
      </a>
    </div>
    <div class="nav-menu" data-nav-track>
      <span class="nav-indicator" aria-hidden="true"></span>
      <a class="{{.HomeClass}}" data-nav-item href="/">首页</a>
      <a class="{{.AboutClass}}" data-nav-item href="/about">关于</a>
      <a class="{{.APIClass}}" data-nav-item href="/api">接口</a>
      <a class="{{.ContactClass}}" data-nav-item href="/contact">联系</a>
    </div>
  </div>
</nav>
<script>(function(){var menus=document.querySelectorAll('[data-nav-track]');if(!menus.length)return;menus.forEach(function(menu){var indicator=menu.querySelector('.nav-indicator');var items=menu.querySelectorAll('[data-nav-item]');if(!indicator||!items.length)return;var active=menu.querySelector('[data-nav-item].is-active')||items[0];function moveTo(el){if(!el)return;indicator.style.width=el.offsetWidth+'px';indicator.style.height=el.offsetHeight+'px';indicator.style.transform='translate('+el.offsetLeft+'px,'+el.offsetTop+'px)';indicator.style.opacity='1';}moveTo(active);items.forEach(function(item){item.addEventListener('mouseenter',function(){moveTo(item);});item.addEventListener('focus',function(){moveTo(item);});});menu.addEventListener('mouseleave',function(){moveTo(active);});menu.addEventListener('focusout',function(event){if(!menu.contains(event.relatedTarget))moveTo(active);});window.addEventListener('resize',function(){moveTo(active);});});})();</script>`

const footerTemplate = `<section class="footer">
  <div class="xdb-meta"><span class="tag">版本日期</span><span class="xdb-note">ip2region 付费版数据库</span> · 上次更新日期：<b>{{.FooterMeta.UpdatedAt}}</b> · 下次更新日期：<b>{{.FooterMeta.NextUpdateAt}}</b></div>
</section>
<footer class="site-footer">
  <div class="site-footer-inner">
    <span class="site-copyright">© {{.Year}} <a class="copyright-brand" href="https://www.bluecdn.com/" target="_blank" rel="noreferrer">Bluecdn.com</a> All rights reserved.</span>
    <div class="site-socials" aria-label="Social links">
      <a href="https://x.com/bluecdn" target="_blank" rel="noreferrer" aria-label="X"><i class="fa-brands fa-x-twitter" aria-hidden="true"></i></a>
      <a href="https://github.com/bluecdn" target="_blank" rel="noreferrer" aria-label="GitHub"><i class="fa-brands fa-github" aria-hidden="true"></i></a>
      <a href="https://www.linkedin.com/company/bluecdn/" target="_blank" rel="noreferrer" aria-label="LinkedIn"><i class="fa-brands fa-linkedin-in" aria-hidden="true"></i></a>
      <a href="https://t.me/bluecdn" target="_blank" rel="noreferrer" aria-label="Telegram"><i class="fa-brands fa-telegram" aria-hidden="true"></i></a>
    </div>
  </div>
</footer>`

const homeTemplate = `<!doctype html>
<html lang="{{.P.Lang}}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{{.P.Title}}</title>
  <meta name="description" content="{{.P.Description}}" />
  ` + commonHead + `
</head>
<body class="min-h-screen bg-background text-foreground overflow-x-hidden">
  {{.Header}}
  <main class="wrap">
    <section class="hero-landing">
      <h1 class="hero-landing-title">IP Address Lookup</h1>
      <p class="hero-landing-sub">快速识别访问者 IP，支持 <span class="font-cairo-play">IPv4</span> / <span class="font-cairo-play">IPv6</span> 查询与 GeoIP 结果展示。</p>
      <div class="hero-landing-actions">
        <a class="hero-action-btn hero-action-btn-primary" href="/api">API</a>
        <button id="hero-focus-query" class="hero-action-btn hero-action-btn-ghost" type="button">查询</button>
      </div>
    </section>

    <section id="query-panel" class="hero">
      <div class="hero-title-row">
        <h1 class="title">{{.P.QueryHeading}}</h1>
        <div id="status" class="status status-query">{{.P.StatusText}}</div>
      </div>
      <p class="sub">首页会自动识别当前访问者 IP；输入 <span class="font-cairo-play">IPv4/IPv6</span> 后，将通过 <code class="font-cairo-play">/geoip/{ip}</code> 返回对应地理信息。</p>
      <form id="ip-query-form" class="ip-query" autocomplete="off">
        <input id="ip-query-input" class="ip-query-input" type="text" inputmode="text" placeholder="输入 IPv4 / IPv6，例如 8.8.8.8 或 2001:4860:4860::8888" aria-label="IP address">
        <button class="ip-query-btn" type="submit">查询 IP</button>
      </form>
    </section>

    <section class="panel">
      <h2 class="panel-title">实时识别结果</h2>
      <div id="result-groups" class="result-groups">
        <section id="group-v4" class="result-group" hidden>
          <h3 class="result-title">IPv4（来自 ipv4.bluecdn.com）</h3>
          <section id="grid-v4" class="grid"></section>
        </section>
        <section id="group-v6" class="result-group" hidden>
          <h3 class="result-title">IPv6（来自 ipv6.bluecdn.com）</h3>
          <section id="grid-v6" class="grid"></section>
        </section>
        <section id="group-query" class="result-group" hidden>
          <h3 class="result-title">查询结果</h3>
          <section id="grid-query" class="grid"></section>
        </section>
      </div>
    </section>

    {{.Footer}}
  </main>
  <script id="field-config" type="application/json">{{.P.FieldsJSON}}</script>
  <script id="initial-geoip" type="application/json">{{.P.InitialGeoJSON}}</script>
  <script id="initial-query-ip" type="application/json">{{.P.InitialQueryIP}}</script>
  <script src="/assets/main.js"></script>
</body>
</html>`

const apiTemplate = `<!doctype html>
<html lang="{{.P.Lang}}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{{.P.Title}}</title>
  <meta name="description" content="{{.P.Description}}" />
  ` + commonHead + `
</head>
<body class="api-page min-h-screen bg-background text-foreground overflow-x-hidden">
  {{.Header}}
  <main class="wrap">
    <section class="hero">
      <h1 class="title">API Documentation</h1>
      <p class="sub">提供 IP、JSON IP、GeoIP 接口。支持双栈查询、指定 IP 定位、脚本调用与浏览器跨域调用。</p>
      <div class="status">Base URL: <code>https://ip.bluecdn.com</code></div>
    </section>

    <section class="panel">
      <h2 class="panel-title">接口总览</h2>
      <div class="grid">
        <div class="result-row"><span class="k">GET /ip</span><span class="v">纯文本返回访问者公网 IP</span></div>
        <div class="result-row"><span class="k">GET /jsonip</span><span class="v">JSON 返回访问者 IP</span></div>
        <div class="result-row"><span class="k">GET /geoip</span><span class="v">JSON 返回访问者 IP 地理信息</span></div>
        <div class="result-row"><span class="k">GET /geoip/{ip}</span><span class="v">JSON 返回指定 IP 地理信息（支持 IPv4 / IPv6）</span></div>
      </div>
    </section>

    <section class="panel">
      <h2 class="panel-title">IP（纯文本）</h2>
      <p class="doc-lead">返回访问者公网 IP（IPv4 或 IPv6）纯文本。</p>
      <div class="api-lang-grid">
        <article class="api-lang-card">
          <h3 class="api-lang-title">中文说明</h3>
          <div class="api-table-wrap"><table class="api-table"><thead><tr><th>模式</th><th>可点击地址</th><th>命令</th></tr></thead><tbody>
            <tr><td>双栈（IPv4 + IPv6）</td><td><a class="api-link" href="https://ip.bluecdn.com/ip" target="_blank" rel="noopener noreferrer">https://ip.bluecdn.com/ip</a></td><td><span class="cmd-chip"><code class="inline-code">curl ip.bluecdn.com</code><button class="copy-icon-btn" type="button" data-copy-text="curl ip.bluecdn.com" aria-label="复制命令"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></span></td></tr>
            <tr><td>仅 IPv4</td><td><a class="api-link" href="https://ipv4.bluecdn.com" target="_blank" rel="noopener noreferrer">https://ipv4.bluecdn.com</a></td><td><span class="cmd-chip"><code class="inline-code">curl -4 ip.bluecdn.com</code><button class="copy-icon-btn" type="button" data-copy-text="curl -4 ip.bluecdn.com" aria-label="复制命令"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></span><span class="cmd-sep"> / </span><span class="cmd-chip"><code class="inline-code">curl ipv4.bluecdn.com</code><button class="copy-icon-btn" type="button" data-copy-text="curl ipv4.bluecdn.com" aria-label="复制命令"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></span></td></tr>
            <tr><td>仅 IPv6</td><td><a class="api-link" href="https://ipv6.bluecdn.com" target="_blank" rel="noopener noreferrer">https://ipv6.bluecdn.com</a></td><td><span class="cmd-chip"><code class="inline-code">curl -6 ip.bluecdn.com</code><button class="copy-icon-btn" type="button" data-copy-text="curl -6 ip.bluecdn.com" aria-label="复制命令"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></span><span class="cmd-sep"> / </span><span class="cmd-chip"><code class="inline-code">curl ipv6.bluecdn.com</code><button class="copy-icon-btn" type="button" data-copy-text="curl ipv6.bluecdn.com" aria-label="复制命令"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></span></td></tr>
          </tbody></table></div>
        </article>
        <article class="api-lang-card api-lang-card-en">
          <h3 class="api-lang-title">English</h3>
          <div class="api-table-wrap"><table class="api-table"><thead><tr><th>Mode</th><th>Clickable Endpoint</th><th>Command</th></tr></thead><tbody>
            <tr><td>Dual Stack (IPv4 + IPv6)</td><td><a class="api-link" href="https://ip.bluecdn.com/ip" target="_blank" rel="noopener noreferrer">https://ip.bluecdn.com/ip</a></td><td><span class="cmd-chip"><code class="inline-code">curl ip.bluecdn.com</code><button class="copy-icon-btn" type="button" data-copy-text="curl ip.bluecdn.com" aria-label="Copy command"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></span></td></tr>
            <tr><td>IPv4 Only</td><td><a class="api-link" href="https://ipv4.bluecdn.com" target="_blank" rel="noopener noreferrer">https://ipv4.bluecdn.com</a></td><td><span class="cmd-chip"><code class="inline-code">curl -4 ip.bluecdn.com</code><button class="copy-icon-btn" type="button" data-copy-text="curl -4 ip.bluecdn.com" aria-label="Copy command"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></span><span class="cmd-sep"> / </span><span class="cmd-chip"><code class="inline-code">curl ipv4.bluecdn.com</code><button class="copy-icon-btn" type="button" data-copy-text="curl ipv4.bluecdn.com" aria-label="Copy command"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></span></td></tr>
            <tr><td>IPv6 Only</td><td><a class="api-link" href="https://ipv6.bluecdn.com" target="_blank" rel="noopener noreferrer">https://ipv6.bluecdn.com</a></td><td><span class="cmd-chip"><code class="inline-code">curl -6 ip.bluecdn.com</code><button class="copy-icon-btn" type="button" data-copy-text="curl -6 ip.bluecdn.com" aria-label="Copy command"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></span><span class="cmd-sep"> / </span><span class="cmd-chip"><code class="inline-code">curl ipv6.bluecdn.com</code><button class="copy-icon-btn" type="button" data-copy-text="curl ipv6.bluecdn.com" aria-label="Copy command"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></span></td></tr>
          </tbody></table></div>
        </article>
      </div>
    </section>

    <section class="panel"><h2 class="panel-title">JSON IP（JSON）</h2><div class="cmd-grid"><article class="terminal"><div class="terminal-head">JSON</div><pre><code>$ curl ip.bluecdn.com/jsonip
{"ip":"203.0.113.10"}</code></pre></article></div></section>
    <section class="panel"><h2 class="panel-title">GeoIP（JSON）</h2><div class="cmd-grid"><article class="terminal"><div class="terminal-head">当前访问者 IP</div><pre><code>$ curl ip.bluecdn.com/geoip</code></pre></article><article class="terminal"><div class="terminal-head">指定 IP（测试 IP）</div><pre><code>$ curl ip.bluecdn.com/geoip/8.8.8.8</code></pre></article></div></section>

    <section class="panel">
      <h2 class="panel-title">多语言调用示例</h2>
      <div class="lang-tabs" role="tablist" aria-label="Language tabs">
        <button class="lang-tab is-active" type="button" role="tab" aria-selected="true" data-lang-target="lang-node">Node.js</button>
        <button class="lang-tab" type="button" role="tab" aria-selected="false" data-lang-target="lang-php">PHP</button>
        <button class="lang-tab" type="button" role="tab" aria-selected="false" data-lang-target="lang-python">Python</button>
        <button class="lang-tab" type="button" role="tab" aria-selected="false" data-lang-target="lang-go">Go</button>
      </div>
      <div class="lang-panels">
        <article id="lang-node" class="terminal lang-panel is-active" role="tabpanel"><div class="terminal-head">Node.js</div><button class="lang-code-copy" type="button" aria-label="Copy code"><i class="fa-regular fa-copy" aria-hidden="true"></i></button><pre><code>const ip = await fetch('https://ip.bluecdn.com/ip').then(r =&gt; r.text());
const geo = await fetch('https://ip.bluecdn.com/geoip/8.8.8.8').then(r =&gt; r.json());</code></pre></article>
        <article id="lang-php" class="terminal lang-panel" role="tabpanel" hidden><div class="terminal-head">PHP</div><button class="lang-code-copy" type="button" aria-label="Copy code"><i class="fa-regular fa-copy" aria-hidden="true"></i></button><pre><code>&lt;?php
$ip = trim(file_get_contents('https://ip.bluecdn.com/ip'));
$geo = json_decode(file_get_contents('https://ip.bluecdn.com/geoip/8.8.8.8'), true);</code></pre></article>
        <article id="lang-python" class="terminal lang-panel" role="tabpanel" hidden><div class="terminal-head">Python</div><button class="lang-code-copy" type="button" aria-label="Copy code"><i class="fa-regular fa-copy" aria-hidden="true"></i></button><pre><code>import requests
ip = requests.get('https://ip.bluecdn.com/ip', timeout=10).text.strip()
geo = requests.get('https://ip.bluecdn.com/geoip/8.8.8.8', timeout=10).json()</code></pre></article>
        <article id="lang-go" class="terminal lang-panel" role="tabpanel" hidden><div class="terminal-head">Go</div><button class="lang-code-copy" type="button" aria-label="Copy code"><i class="fa-regular fa-copy" aria-hidden="true"></i></button><pre><code>resp, _ := http.Get("https://ip.bluecdn.com/ip")
ipBytes, _ := io.ReadAll(resp.Body)
fmt.Println(strings.TrimSpace(string(ipBytes)))</code></pre></article>
      </div>
    </section>

    {{.Footer}}
  </main>
  <script>(function(){async function copyText(t){if(navigator.clipboard&&window.isSecureContext){await navigator.clipboard.writeText(t);return;}const ta=document.createElement('textarea');ta.value=t;ta.style.position='fixed';ta.style.opacity='0';document.body.appendChild(ta);ta.focus();ta.select();document.execCommand('copy');ta.remove();}document.querySelectorAll('.copy-icon-btn').forEach((btn)=>{btn.addEventListener('click',async()=>{const icon=btn.querySelector('i');const text=btn.getAttribute('data-copy-text')||'';if(!text||!icon)return;try{await copyText(text);icon.classList.remove('fa-copy','fa-regular');icon.classList.add('fa-check','fa-solid');btn.classList.add('copied');setTimeout(()=>{icon.classList.remove('fa-check','fa-solid');icon.classList.add('fa-copy','fa-regular');btn.classList.remove('copied');},1200);}catch(e){}});});document.querySelectorAll('.lang-code-copy').forEach((btn)=>{btn.addEventListener('click',async()=>{const icon=btn.querySelector('i');const panel=btn.closest('.lang-panel');const codeEl=panel?panel.querySelector('pre code'):null;const text=codeEl?codeEl.textContent||'':'';if(!text||!icon)return;try{await copyText(text);icon.classList.remove('fa-copy','fa-regular');icon.classList.add('fa-check','fa-solid');btn.classList.add('copied');setTimeout(()=>{icon.classList.remove('fa-check','fa-solid');icon.classList.add('fa-copy','fa-regular');btn.classList.remove('copied');},1200);}catch(e){}});});const tabs=document.querySelectorAll('.lang-tab');const panels=document.querySelectorAll('.lang-panel');tabs.forEach((tab)=>{tab.addEventListener('click',()=>{const target=tab.getAttribute('data-lang-target');if(!target)return;tabs.forEach((t)=>{t.classList.remove('is-active');t.setAttribute('aria-selected','false');});panels.forEach((p)=>{p.classList.remove('is-active');p.hidden=true;});const active=document.getElementById(target);if(!active)return;tab.classList.add('is-active');tab.setAttribute('aria-selected','true');active.hidden=false;active.classList.add('is-active');});});})();</script>
</body>
</html>`

const aboutTemplate = `<!doctype html>
<html lang="{{.P.Lang}}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{.P.Title}}</title><meta name="description" content="{{.P.Description}}" />` + commonHead + `</head>
<body class="min-h-screen bg-background text-foreground overflow-x-hidden">{{.Header}}<main class="wrap"><section class="hero"><h1 class="title">关于我们</h1><p class="sub">ip.bluecdn.com 提供面向开发者与企业的公网 IP 与地理信息查询服务。</p></section><section class="panel"><h2 class="panel-title">产品定位</h2><div class="grid"><div class="result-row"><span class="k">服务目标</span><span class="v">稳定、轻量、可集成。</span></div><div class="result-row"><span class="k">支持协议</span><span class="v">IPv4 / IPv6 双栈，HTTP JSON API。</span></div><div class="result-row"><span class="k">数据来源</span><span class="v">ip2region 付费版数据库。</span></div></div></section>{{.Footer}}</main></body></html>`

const contactTemplate = `<!doctype html>
<html lang="{{.P.Lang}}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{.P.Title}}</title><meta name="description" content="{{.P.Description}}" />` + commonHead + `</head>
<body class="min-h-screen bg-background text-foreground overflow-x-hidden">{{.Header}}<main class="wrap"><section class="hero"><h1 class="title">联系</h1><p class="sub">如需技术支持、API 接入协助、企业采购或定制服务，请通过以下方式联系我们。</p></section><section class="panel"><h2 class="panel-title">联系方式</h2><div class="grid"><div class="result-row"><span class="k">官网</span><span class="v"><a href="https://www.bluecdn.com/" target="_blank" rel="noreferrer">https://www.bluecdn.com/</a></span></div><div class="result-row"><span class="k">商务咨询</span><span class="v"><a href="https://www.bluecdn.com/contact" target="_blank" rel="noreferrer">https://www.bluecdn.com/contact</a></span></div><div class="result-row"><span class="k">技术文档</span><span class="v"><a href="/api">/api</a></span></div></div></section>{{.Footer}}</main></body></html>`
