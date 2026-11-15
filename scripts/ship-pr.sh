#!/usr/bin/env bash
# Label a pull request ready-to-merge once its checks allow it, then wait for the merge queue to merge or eject it.
#
# usage: scripts/ship-pr.sh PR [REPO]
# exit:  0 merged, 2 ejected from the merge queue, 3 label lost, 4 checks never settled, 5 a check failed
#
# A pull request that runs the sharded Fast PR gate must pass all of its shards. A pull request whose scope runs no
# shards (scripts, docs) is labelled once every check that did run has passed, and says so. Environment overrides for
# tests and slow hosts: GH (the gh binary), SHIP_POLL_SECONDS (default 60), SHIP_GATE_POLLS (default 120),
# SHIP_QUEUE_POLLS (default 300).
set -u
PR=${1:?usage: ship-pr.sh PR [REPO]}
REPO=${2:-capell-app/capell}
GH=${GH:-gh}
POLL=${SHIP_POLL_SECONDS:-60}
GATE_POLLS=${SHIP_GATE_POLLS:-120}
QUEUE_POLLS=${SHIP_QUEUE_POLLS:-300}

gate="unsettled"
for ((i = 0; i < GATE_POLLS; i++)); do
  out=$("$GH" pr checks "$PR" -R "$REPO" 2>/dev/null || true)
  if [ -n "$out" ]; then
    failed=$(printf '%s\n' "$out" | awk -F'\t' '$2 == "fail"')
    if [ -n "$failed" ]; then
      echo "CHECK FAILED $PR"
      printf '%s\n' "$failed"
      exit 5
    fi
    pending=$(printf '%s\n' "$out" | awk -F'\t' '$2 == "pending"' | wc -l | tr -d ' ')
    shards=$(printf '%s\n' "$out" | awk -F'\t' '$1 ~ /Fast PR gate.*shard/' | wc -l | tr -d ' ')
    shards_passed=$(printf '%s\n' "$out" | awk -F'\t' '$1 ~ /Fast PR gate.*shard/ && $2 == "pass"' | wc -l | tr -d ' ')
    if [ "$pending" = 0 ]; then
      if [ "$shards" -gt 0 ] && [ "$shards_passed" = "$shards" ]; then
        gate="all $shards shards passed"
        break
      fi
      if [ "$shards" = 0 ]; then
        gate="no shard checks for this pull request; every check that ran passed"
        break
      fi
    fi
  fi
  sleep "$POLL"
done

if [ "$gate" = "unsettled" ]; then
  echo "CHECKS NEVER SETTLED $PR: still pending or absent after $GATE_POLLS polls"
  exit 4
fi

echo "GATE OK $PR: $gate"
"$GH" pr edit "$PR" -R "$REPO" --add-label ready-to-merge >/dev/null 2>&1 || { echo "LABEL FAILED $PR"; exit 5; }

owner=${REPO%%/*}
name=${REPO##*/}
query='query($o:String!,$r:String!,$n:Int!){repository(owner:$o,name:$r){pullRequest(number:$n){timelineItems(itemTypes:[REMOVED_FROM_MERGE_QUEUE_EVENT]){totalCount}}}}'
ejections() {
  "$GH" api graphql -f query="$query" -F o="$owner" -F r="$name" -F n="$PR" -q '.data.repository.pullRequest.timelineItems.totalCount' 2>/dev/null
}
base=$(ejections || true)
base=${base:-0}
for ((i = 0; i < QUEUE_POLLS; i++)); do
  sleep "$POLL"
  state=$("$GH" pr view "$PR" -R "$REPO" --json state,labels -q '.state+" "+([.labels[].name]|join(","))' 2>/dev/null) || continue
  case "$state" in MERGED*) echo "MERGED $PR"; exit 0 ;; esac
  now=$(ejections || true)
  if [ -n "$now" ] && [ "$now" != "$base" ]; then
    echo "EJECTED $PR ($base -> $now)"
    exit 2
  fi
  case "$state" in *ready-to-merge*) ;; *) echo "LABEL LOST $PR: $state"; exit 3 ;; esac
done
echo "QUEUE TIMEOUT $PR"
exit 4
