# Testing Guide

## To Test in CMS

### Site Settings
1. Go to Settings > Cache Control
2. Check "Enable Cache Control" checkbox
3. You should see:
   - Cache Type radio buttons (public/private)
   - Cache Duration radio buttons (maxage/nostore)  
   - When "maxage" selected: MaxAge field and Must Revalidate checkbox
   - When "nostore" selected: MaxAge and Must Revalidate should hide

### Page Settings  
1. Go to any page > Cache Control tab
2. You should see current cache header displayed
3. Check "Override Site Cache Settings"
4. You should see:
   - All the same fields as site settings
   - Fields should be pre-filled with site defaults

## Known Issues to Check
1. Are fields appearing when "Override" is checked?
2. Is the CacheDuration radio showing both options (maxage and nostore)?
3. Do fields hide/show correctly with DisplayLogic?

## If Fields Don't Appear
- Check browser console for JavaScript errors
- Try dev/build?flush=all
- Check that DisplayLogic module is installed
