# Sample Files Directory

This directory contains sample XML files from the Enterprise CAD system for testing and development.

## Files

Please add the following sample files here:

1. `260_2022120307164448.xml` - Sample CAD incident
2. `261_2022120307162437.xml` - Sample CAD incident  
3. `285_2022120307195970.xml` - Sample CAD incident
4. `287_2022120307210477.xml` - Sample CAD incident

## Usage

Once files are added, they can be tested with:

```bash
# Copy a sample file to the watch folder
cp samples/260_2022120307164448.xml watch/

# Monitor the logs
docker compose logs -f app
```

## File Format

These files should conform to the Enterprise CAD Standard Exporter format as documented in `docs/Enterprise_CAD_Standard_Exporter_Fact_Sheet.pdf`.
