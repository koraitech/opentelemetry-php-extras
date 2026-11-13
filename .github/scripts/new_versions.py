#!/usr/bin/env python3
"""Finds PHP and Laravel versions released after the ones the package is tested on.

  new_versions.py detect   writes `include` (new test entries, JSON), `found` and `title` to $GITHUB_OUTPUT
  new_versions.py apply    adds those entries to .github/versions.json, composer.json and README.md

Sources: php.net (active PHP branches) and Packagist (laravel/framework stable releases).
"""
import json
import os
import re
import sys
import urllib.request

VERSIONS = ".github/versions.json"
COMPOSER = "composer.json"
README = "README.md"


def fetch(url):
    req = urllib.request.Request(url, headers={"User-Agent": "opentelemetry-php-extras-version-check"})
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.load(r)


def key(v):
    return tuple(int(p) for p in v.split("."))


def laravel_major(constraint):
    return int(re.match(r"\^(\d+)", constraint).group(1))


def active_php():
    data = fetch("https://www.php.net/releases/active")
    return sorted({minor for branches in data.values() for minor in branches}, key=key)


def latest_laravel_major():
    releases = fetch("https://repo.packagist.org/p2/laravel/framework.json")["packages"]["laravel/framework"]
    majors = [int(m.group(1)) for r in releases if (m := re.fullmatch(r"v?(\d+)\.\d+\.\d+", r["version"]))]
    return max(majors)


def candidates(matrix):
    tested_php = sorted({e["php"] for e in matrix}, key=key)
    tested_laravel = sorted({laravel_major(e["laravel"]) for e in matrix})
    new = []
    newest_laravel = f"^{tested_laravel[-1]}.0"
    for php in active_php():
        if key(php) > key(tested_php[-1]):
            new.append({"php": php, "laravel": newest_laravel})
    newest_php = new[-1]["php"] if new else tested_php[-1]
    for major in range(tested_laravel[-1] + 1, latest_laravel_major() + 1):
        new.append({"php": newest_php, "laravel": f"^{major}.0"})
    return new


def title(new):
    php = sorted({e["php"] for e in new if not any(e["php"] == x["php"] for x in load()["include"])}, key=key)
    laravel = sorted({laravel_major(e["laravel"]) for e in new} - {laravel_major(e["laravel"]) for e in load()["include"]})
    parts = [f"PHP {v}" for v in php] + [f"Laravel {v}" for v in laravel]
    return "support " + " and ".join(parts)


def load():
    with open(VERSIONS) as f:
        return json.load(f)


def output(**values):
    with open(os.environ.get("GITHUB_OUTPUT", "/dev/stdout"), "a") as f:
        for k, v in values.items():
            f.write(f"{k}={v}\n")


def tested_line(matrix):
    php = sorted({e["php"] for e in matrix}, key=key)
    laravel = sorted({laravel_major(e["laravel"]) for e in matrix})
    return f"Tested on PHP {', '.join(php[:-1])} and {php[-1]}, and Laravel {laravel[0]} to {laravel[-1]}."


def apply(new):
    data = load()
    data["include"].extend(new)
    data["include"].sort(key=lambda e: (key(e["php"]), laravel_major(e["laravel"])))
    with open(VERSIONS, "w") as f:
        f.write("{\n  \"include\": [\n")
        f.write(",\n".join(f"    {{ \"php\": \"{e['php']}\", \"laravel\": \"{e['laravel']}\" }}" for e in data["include"]))
        f.write("\n  ]\n}\n")

    majors = sorted({laravel_major(e["laravel"]) for e in data["include"]})
    constraint = " || ".join(f"^{m}.0" for m in majors)
    with open(COMPOSER) as f:
        composer = f.read()
    composer = re.sub(r'("illuminate/events": )"[^"]*"', rf'\1"{constraint}"', composer)
    with open(COMPOSER, "w") as f:
        f.write(composer)

    with open(README) as f:
        readme = f.read()
    readme = re.sub(r"(<!-- tested -->).*?(<!-- /tested -->)", rf"\g<1>{tested_line(data['include'])}\g<2>", readme, flags=re.S)
    with open(README, "w") as f:
        f.write(readme)


def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ("detect", "apply"):
        sys.exit(__doc__)
    if sys.argv[1] == "detect":
        new = candidates(load()["include"])
        output(found="true" if new else "false", include=json.dumps({"include": new}), title=title(new) if new else "")
        print(json.dumps(new) if new else "Nothing new: tested versions are current.", file=sys.stderr)
    else:
        apply(json.loads(os.environ["NEW_ENTRIES"])["include"])


if __name__ == "__main__":
    main()
