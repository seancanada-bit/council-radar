/**
 * Scrape CivicInfo BC for elected officials across all BC municipalities
 * and regional districts.
 *
 * Runs in GitHub Actions with Puppeteer (headless Chrome) to bypass
 * Cloudflare's JavaScript challenge.
 *
 * Output: config/civicinfo_officials.json
 *
 * CivicInfo BC URL patterns:
 *   Municipality page: /municipalities?id={0-200}
 *   Regional district: /regionaldistricts?id={0-200}
 *   Person search:     /people?type=ss&stext={name}
 *   Person page:       /people?id={id}
 *
 * Each municipality/RD page lists elected officials by name and role.
 * Individual person pages have email addresses and phone numbers.
 */

const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

const BASE_URL = 'https://www.civicinfo.bc.ca';
const OUTPUT_FILE = path.join(__dirname, '..', 'config', 'civicinfo_officials.json');

// Rate limiting
const PAGE_DELAY_MS = 2000;
const PERSON_DELAY_MS = 1500;

// Our 16 monitored municipalities and their CivicInfo IDs
// (ID found from URL pattern: /municipalities?id=XX)
// We'll also scrape all others we find
const PRIORITY_MUNICIPALITIES = [
    // These are scraped first
    { name: 'Parksville', id: 90 },
    { name: 'Kamloops', id: 50 },
    { name: 'Cranbrook', id: 22 },
    { name: 'Colwood', id: 15 },
    { name: 'Smithers', id: 124 },
    { name: 'Quesnel', id: 103 },
    { name: 'Trail', id: 134 },
    { name: 'Revelstoke', id: 107 },
    { name: 'Nanaimo', id: 78 },
    { name: 'Victoria', id: 141 },
    { name: 'Kelowna', id: 52 },
    { name: 'Clearwater', id: 186 },
    { name: 'Mackenzie', id: 68 },
    { name: 'Houston', id: 47 },
    { name: 'Stewart', id: 127 },
];

// We'll discover Sun Peaks and others by scanning ID ranges

async function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

async function launchBrowser() {
    return puppeteer.launch({
        headless: 'new',
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
        ],
    });
}

/**
 * Navigate to a page and wait for Cloudflare challenge to resolve
 */
async function navigateWithCloudflare(page, url, maxWait = 15000) {
    await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });

    // Check if we hit a Cloudflare challenge
    const title = await page.title();
    if (title.includes('Just a moment') || title.includes('Cloudflare')) {
        console.log('  Cloudflare challenge detected, waiting...');
        // Wait for the challenge to resolve (page title changes)
        await page.waitForFunction(
            () => !document.title.includes('Just a moment'),
            { timeout: maxWait }
        );
        await sleep(2000); // Extra wait for page to fully load
    }
}

/**
 * Scrape a municipality or regional district page for elected officials
 */
async function scrapeMunicipalityPage(page, id, type = 'municipalities') {
    const url = `${BASE_URL}/${type}?id=${id}`;

    try {
        await navigateWithCloudflare(page, url);
        await sleep(PAGE_DELAY_MS);

        // Check if the page has content (some IDs don't exist)
        const content = await page.content();
        if (content.includes('Page not found') || content.includes('404')) {
            return null;
        }

        // Extract municipality/RD name from h1 or page content
        const orgName = await page.evaluate(() => {
            // Look for the org name - usually in a specific heading or title area
            const h1s = document.querySelectorAll('h1, h2, .org-name, [class*="title"]');
            for (const el of h1s) {
                const text = el.textContent.trim();
                if (text && text.length > 2 && text.length < 100
                    && !text.includes('Search') && !text.includes('Login')
                    && !text.includes('Find')) {
                    return text;
                }
            }
            // Try the page title
            const title = document.title || '';
            const match = title.match(/Municipality:\s*(.+?)(?:\s*\||\s*$)/);
            if (match) return match[1].trim();
            const match2 = title.match(/Regional District:\s*(.+?)(?:\s*\||\s*$)/);
            if (match2) return match2[1].trim();
            return '';
        });

        if (!orgName) {
            return null;
        }

        // Extract elected officials section
        const officials = await page.evaluate(() => {
            const results = [];
            const bodyText = document.body.innerText;

            // Find the "Elected Officials" section
            // CivicInfo pages list them as "Name  Role" pairs
            const electedMatch = bodyText.match(/Elected Officials\s*\n([\s\S]*?)(?:Staff|$)/);
            if (!electedMatch) return results;

            const section = electedMatch[1];
            const lines = section.split('\n').map(l => l.trim()).filter(l => l);

            for (let i = 0; i < lines.length; i++) {
                const line = lines[i];
                // Pattern: "Name  Role" on same line or "Name" then "Role" on next line
                // Roles: Mayor, Councillor, Chair, Director, Chief, Regional District Chair
                const nameRoleMatch = line.match(/^(.+?)\s{2,}(Mayor|Councillor|Chair|Vice Chair|Director)$/);
                if (nameRoleMatch) {
                    results.push({
                        name: nameRoleMatch[1].trim(),
                        role: nameRoleMatch[2].trim(),
                    });
                    continue;
                }

                // Check if next line is a role
                if (i + 1 < lines.length) {
                    const nextLine = lines[i + 1];
                    if (/^(Mayor|Councillor|Chair|Vice Chair|Director)$/.test(nextLine)) {
                        results.push({
                            name: line,
                            role: nextLine,
                        });
                        i++; // Skip the role line
                    }
                }
            }

            return results;
        });

        // Also try to find person page links for each official
        const personLinks = await page.evaluate(() => {
            const links = [];
            const anchors = document.querySelectorAll('a[href*="/people?id="]');
            for (const a of anchors) {
                const href = a.getAttribute('href');
                const idMatch = href.match(/id=(\d+)/);
                if (idMatch) {
                    links.push({
                        name: a.textContent.trim(),
                        personId: parseInt(idMatch[1]),
                    });
                }
            }
            return links;
        });

        return {
            orgName,
            type,
            id,
            officials,
            personLinks,
        };
    } catch (err) {
        console.log(`  Error scraping ${type}?id=${id}: ${err.message}`);
        return null;
    }
}

/**
 * Scrape a person page for email and phone
 */
async function scrapePersonPage(page, personId) {
    const url = `${BASE_URL}/people?id=${personId}`;

    try {
        await navigateWithCloudflare(page, url);
        await sleep(PERSON_DELAY_MS);

        const contactInfo = await page.evaluate(() => {
            const text = document.body.innerText;
            const result = { email: '', phone: '', title: '' };

            // Email - look for mailto links or email pattern
            const mailtoEl = document.querySelector('a[href^="mailto:"]');
            if (mailtoEl) {
                result.email = mailtoEl.getAttribute('href').replace('mailto:', '').trim();
            } else {
                const emailMatch = text.match(/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/);
                if (emailMatch && !emailMatch[1].includes('civicinfo')) {
                    result.email = emailMatch[1];
                }
            }

            // Phone
            const phoneMatch = text.match(/Phone\s*[:(]?\s*(\(?\d{3}\)?[\s.-]\d{3}[\s.-]\d{4})/);
            if (phoneMatch) {
                result.phone = phoneMatch[1].trim();
            }

            // Title/role
            const titleMatch = text.match(/Primary Job Title\s+(.+)/);
            if (titleMatch) {
                result.title = titleMatch[1].trim();
            }

            return result;
        });

        return contactInfo;
    } catch (err) {
        console.log(`  Error scraping person ${personId}: ${err.message}`);
        return null;
    }
}

async function main() {
    console.log('Starting CivicInfo BC scrape...');

    const browser = await launchBrowser();
    const page = await browser.newPage();
    await page.setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

    const allOfficials = [];
    const scrapedOrgs = new Set();

    // Phase 1: Scrape our priority municipalities
    console.log('\n=== Phase 1: Priority municipalities ===');
    for (const muni of PRIORITY_MUNICIPALITIES) {
        console.log(`Scraping: ${muni.name} (id=${muni.id})`);
        const result = await scrapeMunicipalityPage(page, muni.id, 'municipalities');

        if (result && result.officials.length > 0) {
            console.log(`  Found ${result.officials.length} officials in ${result.orgName}`);
            scrapedOrgs.add(muni.id);

            // Fetch person pages for email/phone
            for (const link of result.personLinks) {
                const official = result.officials.find(o =>
                    o.name === link.name || link.name.includes(o.name) || o.name.includes(link.name)
                );

                console.log(`  Looking up: ${link.name} (person ${link.personId})`);
                const contact = await scrapePersonPage(page, link.personId);

                if (contact) {
                    allOfficials.push({
                        name: link.name,
                        role: official ? official.role : (contact.title || 'Councillor'),
                        jurisdiction: result.orgName,
                        level: 'municipal',
                        email: contact.email,
                        phone: contact.phone,
                        source: 'civicinfo.bc.ca',
                        person_id: link.personId,
                    });
                }
            }

            // Add any officials without person links (no email lookup possible)
            for (const official of result.officials) {
                const alreadyAdded = allOfficials.some(o =>
                    o.name === official.name && o.jurisdiction === result.orgName
                );
                if (!alreadyAdded) {
                    allOfficials.push({
                        name: official.name,
                        role: official.role,
                        jurisdiction: result.orgName,
                        level: 'municipal',
                        email: '',
                        phone: '',
                        source: 'civicinfo.bc.ca',
                        person_id: null,
                    });
                }
            }
        } else {
            console.log(`  No officials found or page doesn't exist`);
        }
    }

    // Phase 2: Scan all municipality IDs (0-200) for ones we haven't covered
    console.log('\n=== Phase 2: Scanning all municipalities ===');
    for (let id = 0; id <= 200; id++) {
        if (scrapedOrgs.has(id)) continue;

        const result = await scrapeMunicipalityPage(page, id, 'municipalities');
        if (!result || result.officials.length === 0) continue;

        console.log(`  Found: ${result.orgName} (id=${id}, ${result.officials.length} officials)`);
        scrapedOrgs.add(id);

        // For non-priority municipalities, just get person links with emails
        for (const link of result.personLinks) {
            const official = result.officials.find(o =>
                o.name === link.name || link.name.includes(o.name) || o.name.includes(link.name)
            );

            const contact = await scrapePersonPage(page, link.personId);
            if (contact) {
                allOfficials.push({
                    name: link.name,
                    role: official ? official.role : (contact.title || 'Councillor'),
                    jurisdiction: result.orgName,
                    level: 'municipal',
                    email: contact.email,
                    phone: contact.phone,
                    source: 'civicinfo.bc.ca',
                    person_id: link.personId,
                });
            }
        }

        // Add officials without person links
        for (const official of result.officials) {
            const alreadyAdded = allOfficials.some(o =>
                o.name === official.name && o.jurisdiction === result.orgName
            );
            if (!alreadyAdded) {
                allOfficials.push({
                    name: official.name,
                    role: official.role,
                    jurisdiction: result.orgName,
                    level: 'municipal',
                    email: '',
                    phone: '',
                    source: 'civicinfo.bc.ca',
                    person_id: null,
                });
            }
        }
    }

    // Phase 3: Regional districts
    console.log('\n=== Phase 3: Regional districts ===');
    for (let id = 150; id <= 200; id++) {
        const result = await scrapeMunicipalityPage(page, id, 'regionaldistricts');
        if (!result || result.officials.length === 0) continue;

        console.log(`  Found: ${result.orgName} (id=${id}, ${result.officials.length} officials)`);

        for (const link of result.personLinks) {
            const official = result.officials.find(o =>
                o.name === link.name || link.name.includes(o.name) || o.name.includes(link.name)
            );

            const contact = await scrapePersonPage(page, link.personId);
            if (contact) {
                allOfficials.push({
                    name: link.name,
                    role: official ? official.role : (contact.title || 'Director'),
                    jurisdiction: result.orgName,
                    level: 'regional_district',
                    email: contact.email,
                    phone: contact.phone,
                    source: 'civicinfo.bc.ca',
                    person_id: link.personId,
                });
            }
        }

        for (const official of result.officials) {
            const alreadyAdded = allOfficials.some(o =>
                o.name === official.name && o.jurisdiction === result.orgName
            );
            if (!alreadyAdded) {
                allOfficials.push({
                    name: official.name,
                    role: official.role,
                    jurisdiction: result.orgName,
                    level: 'regional_district',
                    email: '',
                    phone: '',
                    source: 'civicinfo.bc.ca',
                    person_id: null,
                });
            }
        }
    }

    await browser.close();

    // Write results
    const withEmail = allOfficials.filter(o => o.email).length;
    console.log(`\n=== Results ===`);
    console.log(`Total officials: ${allOfficials.length}`);
    console.log(`With email: ${withEmail}`);

    fs.writeFileSync(OUTPUT_FILE, JSON.stringify(allOfficials, null, 2));
    console.log(`Written to: ${OUTPUT_FILE}`);
}

main().catch(err => {
    console.error('Fatal error:', err);
    process.exit(1);
});
