# Changelog

## [Unreleased]

### Fixed
- A label that failed to come at booking can be fetched again: `slip()` (GetSlip) finds the
  barcode's shipment id (trackByBarcode, as cancel() does) and asks getLabels for it; a comment
  claimed it before, while GetSlip was not supported.
